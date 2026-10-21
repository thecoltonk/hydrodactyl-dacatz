<?php

namespace Pterodactyl\Services\MinecraftPlayers;

use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Edits a player's inventory and ender chest.
 *
 * Offline players: the saved .dat file is decoded losslessly, the touched slots
 * are rewritten and the file is saved back (the previous file is kept next to
 * it as <uuid>.dat.hydro-bak). Online players: the server would overwrite the
 * file on its next save, so changes are sent as /item replace commands instead.
 */
class InventoryEditor
{
    /** 1.20.5: item stacks use components + an int "count". */
    private const DV_COMPONENTS = 3837;

    /** 1.21.5: armour and offhand moved into the "equipment" compound. */
    private const DV_EQUIPMENT = 4325;

    /** 1.17: /item replace exists. */
    private const DV_ITEM_COMMAND = 2724;

    /** 1.13: flattening; older stacks also need Damage. */
    private const DV_FLATTENING = 1519;

    private const EQUIPMENT = [100 => 'feet', 101 => 'legs', 102 => 'chest', 103 => 'head', 150 => 'offhand'];

    private const COMMAND_SLOTS = [100 => 'armor.feet', 101 => 'armor.legs', 102 => 'armor.chest', 103 => 'armor.head', 150 => 'weapon.offhand'];

    public function __construct(private PlayerFiles $files, private InventoryService $inventory, private TypedNbt $nbt)
    {
    }

    /**
     * @param array<int, array{area: string, slot: int, id: ?string, count: ?int, expect: ?string}> $changes
     *
     * @return array{mode: string, applied: int}
     */
    public function apply(Server $server, string $name, ?string $knownUuid, array $changes, bool $online): array
    {
        ['name' => $name, 'path' => $path, 'content' => $content] = $this->inventory->locate($server, $name, $knownUuid);
        try {
            ['name' => $rootName, 'root' => $root] = $this->nbt->decode($content);
        } catch (\UnexpectedValueException $exception) {
            throw new HttpException(422, 'The saved player data could not be read for editing.', $exception);
        }
        $dataVersion = TypedNbt::intValue(TypedNbt::get($root, 'DataVersion')) ?? 0;
        $equipment = TypedNbt::get($root, 'equipment') !== null || $dataVersion >= self::DV_EQUIPMENT;

        // Validate everything against the current save before touching anything.
        foreach ($changes as $change) {
            $this->validSlot($change['area'], $change['slot']);
            $current = $this->current($root, $change['area'], $change['slot'], $equipment);
            if (($current['id'] ?? null) !== ($change['expect'] ?? null)) {
                throw new HttpException(409, 'The inventory changed since it was loaded. Refresh and try again.');
            }
        }

        if ($online) {
            return $this->applyOnline($server, $name, $root, $changes, $dataVersion, $equipment);
        }

        foreach ($changes as $change) {
            $this->applyToSave($root, $change, $dataVersion, $equipment);
        }
        try {
            $encoded = $this->nbt->encode($rootName, $root);
        } catch (\UnexpectedValueException $exception) {
            throw new HttpException(422, 'The edited player data could not be saved.', $exception);
        }
        $this->files->write($server, $path . '.hydro-bak', $content);
        $this->files->write($server, $path, $encoded);

        return ['mode' => 'offline', 'applied' => count($changes)];
    }

    private function applyOnline(Server $server, string $name, array $root, array $changes, int $dataVersion, bool $equipment): array
    {
        if ($dataVersion > 0 && $dataVersion < self::DV_ITEM_COMMAND) {
            throw new HttpException(422, 'Editing an online player needs Minecraft 1.17 or newer. Have them log off and edit again.');
        }
        $commands = [];
        foreach ($changes as $change) {
            $slot = $this->commandSlot($change['area'], $change['slot']);
            if ($change['id'] === null) {
                $commands[] = sprintf('item replace entity %s %s with minecraft:air', $name, $slot);
                continue;
            }
            $current = $this->current($root, $change['area'], $change['slot'], $equipment);
            if ($current !== null && $current['id'] === $change['id'] && $current['meta']) {
                throw new HttpException(422, 'That item has enchantments or other data, so its count can only be changed while the player is offline.');
            }
            $commands[] = sprintf('item replace entity %s %s with %s %d', $name, $slot, $change['id'], $change['count']);
        }
        foreach ($commands as $command) {
            $this->files->command($server, $command);
        }

        return ['mode' => 'online', 'applied' => count($commands)];
    }

    private function applyToSave(array &$root, array $change, int $dataVersion, bool $equipment): void
    {
        ['area' => $area, 'slot' => $slot, 'id' => $id] = $change;
        $count = (int) ($change['count'] ?? 1);

        if ($area === 'inventory' && $equipment && isset(self::EQUIPMENT[$slot])) {
            $compound = TypedNbt::get($root, 'equipment') ?? TypedNbt::compound();
            $existing = TypedNbt::get($compound, self::EQUIPMENT[$slot]);
            // Converted worlds can still have the old slot in Inventory; drop it.
            $list = TypedNbt::get($root, 'Inventory');
            if ($list !== null && ($index = $this->findIndex($list, $slot)) !== null) {
                $existing ??= $list['v'][$index];
                array_splice($list['v'], $index, 1);
                TypedNbt::set($root, 'Inventory', $list);
            }
            if ($existing !== null) {
                TypedNbt::remove($existing, 'Slot');
            }
            if ($id === null) {
                TypedNbt::remove($compound, self::EQUIPMENT[$slot]);
            } else {
                TypedNbt::set($compound, self::EQUIPMENT[$slot], $this->stack($existing, $id, $count, $dataVersion, null));
            }
            TypedNbt::set($root, 'equipment', $compound);

            return;
        }

        $listName = $area === 'inventory' ? 'Inventory' : 'EnderItems';
        $list = TypedNbt::get($root, $listName) ?? ['t' => 9, 's' => 10, 'v' => []];
        $index = $this->findIndex($list, $slot);
        if ($id === null) {
            if ($index !== null) {
                array_splice($list['v'], $index, 1);
            }
        } else {
            $existing = $index !== null ? $list['v'][$index] : null;
            $stack = $this->stack($existing, $id, $count, $dataVersion, $slot);
            if ($index !== null) {
                $list['v'][$index] = $stack;
            } else {
                $list['v'][] = $stack;
            }
        }
        TypedNbt::set($root, $listName, $list);
    }

    /**
     * Same item: only the count changes and everything else is kept. Different
     * item: a fresh stack in the format this world's data version expects.
     */
    private function stack(?array $existing, string $id, int $count, int $dataVersion, ?int $slot): array
    {
        if ($existing !== null && TypedNbt::stringValue(TypedNbt::get($existing, 'id')) === $id) {
            if (TypedNbt::get($existing, 'count') !== null) {
                TypedNbt::set($existing, 'count', TypedNbt::int($count));
            } else {
                TypedNbt::set($existing, 'Count', TypedNbt::byte(min($count, 127)));
            }

            return $existing;
        }

        $children = [];
        if ($slot !== null) {
            $children['Slot'] = TypedNbt::byte($slot > 127 ? $slot - 256 : $slot);
        }
        $children['id'] = TypedNbt::str($id);
        if ($dataVersion >= self::DV_COMPONENTS) {
            $children['count'] = TypedNbt::int($count);
        } else {
            $children['Count'] = TypedNbt::byte(min($count, 127));
            if ($dataVersion < self::DV_FLATTENING) {
                $children['Damage'] = TypedNbt::short(0);
            }
        }

        return TypedNbt::compound($children);
    }

    /**
     * @return array{id: string, meta: bool}|null
     */
    private function current(array $root, string $area, int $slot, bool $equipment): ?array
    {
        $item = null;
        if ($area === 'inventory' && $equipment && isset(self::EQUIPMENT[$slot])) {
            $item = TypedNbt::get(TypedNbt::get($root, 'equipment'), self::EQUIPMENT[$slot]);
        }
        if ($item === null) {
            $list = TypedNbt::get($root, $area === 'inventory' ? 'Inventory' : 'EnderItems');
            $index = $list === null ? null : $this->findIndex($list, $slot);
            $item = $index === null ? null : $list['v'][$index];
        }
        $id = TypedNbt::stringValue(TypedNbt::get($item, 'id'));
        if ($id === null || $id === 'minecraft:air') {
            return null;
        }
        $meta = false;
        foreach (['components', 'tag'] as $key) {
            $value = TypedNbt::get($item, $key);
            if ($value !== null && $value['v'] !== []) {
                $meta = true;
            }
        }

        return ['id' => $id, 'meta' => $meta];
    }

    private function findIndex(array $list, int $slot): ?int
    {
        if (($list['t'] ?? null) !== 9) {
            return null;
        }
        foreach ($list['v'] as $index => $item) {
            $value = TypedNbt::intValue(TypedNbt::get($item, 'Slot'));
            if ($value !== null && ($value & 255) === $slot) {
                return $index;
            }
        }

        return null;
    }

    private function validSlot(string $area, int $slot): void
    {
        $valid = $area === 'ender_chest'
            ? $slot >= 0 && $slot <= 26
            : ($area === 'inventory' && (($slot >= 0 && $slot <= 35) || isset(self::EQUIPMENT[$slot])));
        if (!$valid) {
            throw new HttpException(422, 'Invalid inventory slot.');
        }
    }

    private function commandSlot(string $area, int $slot): string
    {
        if ($area === 'ender_chest') {
            return 'enderchest.' . $slot;
        }

        return self::COMMAND_SLOTS[$slot] ?? ($slot <= 8 ? 'hotbar.' . $slot : 'inventory.' . ($slot - 9));
    }
}
