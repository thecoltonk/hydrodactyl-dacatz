<?php

/*
 * Ported from Aquadactyl/MCPlayersJava (https://github.com/Aquadactyl/MCPlayersJava).
 * Copyright (c) Aquadactyl contributors. Licensed under the BSD 2-Clause License;
 * see licenses/MCPlayersJava.LICENSE for the full text.
 */

namespace Pterodactyl\Services\MinecraftPlayers;

class PlayerCommand
{
    public function build(string $name, string $action, ?string $reason = null): string
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,16}\z/', $name)
            || ($reason !== null && (mb_strlen($reason) > 120 || preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $reason)))) {
            throw new \InvalidArgumentException('Invalid player name or reason.');
        }
        $command = match ($action) {
            'kick', 'ban', 'pardon', 'op', 'deop' => $action . ' ' . $name,
            'whitelist_add' => 'whitelist add ' . $name,
            'whitelist_remove' => 'whitelist remove ' . $name,
            default => throw new \InvalidArgumentException('Unsupported player action.'),
        };
        if (in_array($action, ['kick', 'ban'], true) && trim($reason ?? '') !== '') {
            $command .= ' ' . trim($reason);
        }

        return $command;
    }
}
