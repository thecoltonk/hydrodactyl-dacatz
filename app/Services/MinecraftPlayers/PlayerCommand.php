<?php

/*
 * Ported from Aquadactyl/MCPlayersJava (https://github.com/Aquadactyl/MCPlayersJava).
 * Copyright (c) Aquadactyl contributors. Licensed under the BSD 2-Clause License;
 * see licenses/MCPlayersJava.LICENSE for the full text.
 */

namespace Pterodactyl\Services\MinecraftPlayers;

class PlayerCommand
{
    /**
     * @param array{mode?: string, dimension?: string, x?: float|int, y?: float|int, z?: float|int, target?: string} $extra
     */
    public function build(string $name, string $action, ?string $reason = null, array $extra = []): string
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,16}\z/', $name)
            || ($reason !== null && (mb_strlen($reason) > 120 || preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $reason)))) {
            throw new \InvalidArgumentException('Invalid player name or reason.');
        }
        $command = match ($action) {
            'kick', 'ban', 'pardon', 'op', 'deop' => $action . ' ' . $name,
            'whitelist_add' => 'whitelist add ' . $name,
            'whitelist_remove' => 'whitelist remove ' . $name,
            'gamemode' => $this->gamemode($name, $extra['mode'] ?? ''),
            'teleport' => $this->teleport($name, $extra),
            'teleport_to_player' => $this->teleportToPlayer($name, $extra['target'] ?? ''),
            default => throw new \InvalidArgumentException('Unsupported player action.'),
        };
        if (in_array($action, ['kick', 'ban'], true) && trim($reason ?? '') !== '') {
            $command .= ' ' . trim($reason);
        }

        return $command;
    }

    private function gamemode(string $name, string $mode): string
    {
        if (!in_array($mode, ['survival', 'creative', 'adventure', 'spectator'], true)) {
            throw new \InvalidArgumentException('Unsupported game mode.');
        }

        return 'gamemode ' . $mode . ' ' . $name;
    }

    private function teleport(string $name, array $extra): string
    {
        $dimension = $extra['dimension'] ?? 'minecraft:overworld';
        if (!is_string($dimension) || !preg_match('~\A[a-z0-9_.-]+:[a-z0-9_./-]+\z~', $dimension) || strlen($dimension) > 128) {
            throw new \InvalidArgumentException('Invalid dimension.');
        }
        $coordinates = [];
        foreach (['x', 'y', 'z'] as $axis) {
            $value = $extra[$axis] ?? null;
            if (!is_numeric($value) || abs((float) $value) > 30000000) {
                throw new \InvalidArgumentException('Invalid coordinates.');
            }
            $coordinates[] = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        }

        return sprintf('execute in %s run tp %s %s', $dimension, $name, implode(' ', $coordinates));
    }

    private function teleportToPlayer(string $name, string $target): string
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,16}\z/', $target)) {
            throw new \InvalidArgumentException('Invalid target player.');
        }

        return 'tp ' . $name . ' ' . $target;
    }
}
