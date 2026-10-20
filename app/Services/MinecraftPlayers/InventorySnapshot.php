<?php

/*
 * Ported from Aquadactyl/MCPlayersJava (https://github.com/Aquadactyl/MCPlayersJava).
 * Copyright (c) Aquadactyl contributors. Licensed under the BSD 2-Clause License;
 * see licenses/MCPlayersJava.LICENSE for the full text.
 */

namespace Pterodactyl\Services\MinecraftPlayers;

class InventorySnapshot
{
    public function fromNbt(array $root): array
    {
        $inventory = [];
        foreach ($this->list($root['Inventory'] ?? []) as $item) {
            if (!is_array($item) || !is_int($item['Slot'] ?? null)) {
                continue;
            }
            $slot = $item['Slot'] & 255;
            if (($slot <= 35 || ($slot >= 100 && $slot <= 103) || $slot === 150)
                && ($stack = $this->item($item, $slot)) !== null) {
                $inventory[$slot] = $stack;
            }
        }
        // Since 1.21.5 armour and offhand are saved in the equipment compound.
        foreach (['head' => 103, 'chest' => 102, 'legs' => 101, 'feet' => 100, 'offhand' => 150] as $key => $slot) {
            $equipment = $root['equipment'][$key] ?? null;
            if (is_array($equipment) && ($stack = $this->item($equipment, $slot)) !== null) {
                $inventory[$slot] = $stack;
            }
        }
        ksort($inventory);
        $ender = [];
        foreach ($this->list($root['EnderItems'] ?? []) as $item) {
            if (is_array($item) && is_int($item['Slot'] ?? null) && $item['Slot'] >= 0 && $item['Slot'] < 27
                && ($stack = $this->item($item, $item['Slot'])) !== null) {
                $ender[$item['Slot']] = $stack;
            }
        }
        ksort($ender);
        $xpProgress = $this->number($root['XpP'] ?? null);

        return [
            'inventory' => array_values($inventory),
            'ender_chest' => array_values($ender),
            'health' => $this->number($root['Health'] ?? null),
            'food' => $this->number($root['foodLevel'] ?? null),
            'xp_level' => $this->number($root['XpLevel'] ?? null),
            'xp_progress' => $xpProgress !== null && $xpProgress >= 0 && $xpProgress <= 1 ? $xpProgress : null,
            'selected_slot' => is_int($root['SelectedItemSlot'] ?? null) && $root['SelectedItemSlot'] >= 0 && $root['SelectedItemSlot'] <= 8 ? $root['SelectedItemSlot'] : null,
            'dimension' => is_string($root['Dimension'] ?? null) ? mb_substr($root['Dimension'], 0, 128) : null,
            'position' => $this->position($root['Pos'] ?? null),
            'data_version' => is_int($root['DataVersion'] ?? null) ? $root['DataVersion'] : null,
        ];
    }

    private function position(mixed $value): ?array
    {
        if (!is_array($value) || count($value) !== 3) {
            return null;
        }
        $position = [];
        foreach (array_values($value) as $coordinate) {
            if ($this->number($coordinate) === null) {
                return null;
            }
            $position[] = round($coordinate, 1);
        }

        return $position;
    }

    private function list(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function number(mixed $value): int|float|null
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? $value : null;
    }

    private function item(array $data, int $slot): ?array
    {
        $id = $data['id'] ?? null;
        $count = $data['count'] ?? $data['Count'] ?? 1;
        if (!isset($data['count']) && is_int($count) && $count < 0) {
            $count &= 255;
        }
        if (!is_string($id) || !preg_match('~\A[a-z0-9_.-]+:[a-z0-9_./-]+\z~', $id)
            || strlen($id) > 160 || !is_int($count) || $count < 1 || $count > 1000000 || $id === 'minecraft:air') {
            return null;
        }
        $metadata = $data['components'] ?? $data['tag'] ?? [];
        $encoded = json_encode($metadata, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $truncated = strlen($encoded) > 16384;

        return [
            'slot' => $slot,
            'id' => $id,
            'count' => $count,
            'metadata' => $truncated ? null : json_decode($encoded, true),
            'metadata_truncated' => $truncated,
        ];
    }
}
