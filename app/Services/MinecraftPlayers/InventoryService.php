<?php

/*
 * Ported from Aquadactyl/MCPlayersJava (https://github.com/Aquadactyl/MCPlayersJava).
 * Copyright (c) Aquadactyl contributors. Licensed under the BSD 2-Clause License;
 * see licenses/MCPlayersJava.LICENSE for the full text.
 */

namespace Pterodactyl\Services\MinecraftPlayers;

use Pterodactyl\Models\Server;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InventoryService
{
    public function __construct(private PlayerFiles $files, private NbtReader $reader, private InventorySnapshot $snapshots)
    {
    }

    /**
     * Resolve the world folder and the UUID the server uses for this player.
     *
     * @return array{name: string, uuid: string, world: string}
     */
    public function resolve(Server $server, string $name, ?string $knownUuid = null): array
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,16}\z/', $name)) {
            throw new HttpException(422, 'Enter a valid Minecraft Java username.');
        }
        $properties = $this->files->read($server, '/server.properties', 65536);
        $world = $this->world($properties);
        $uuid = null;
        $cache = $this->optionalJson($server, '/usercache.json');
        foreach ($cache ?? [] as $player) {
            if (is_array($player) && strcasecmp((string) ($player['name'] ?? ''), $name) === 0 && $this->uuid($player['uuid'] ?? null)) {
                $uuid = strtolower($player['uuid']);
                $name = (string) $player['name'];
                break;
            }
        }
        // Server identity takes priority over Mojang skin identity on offline-mode servers.
        $uuid ??= $this->uuid($knownUuid) ? strtolower($knownUuid) : $this->offlineUuid($name);

        return ['name' => $name, 'uuid' => $uuid, 'world' => $world];
    }

    /**
     * Raw world/stats/<uuid>.json for a player, or null when they have none yet.
     */
    public function stats(Server $server, string $name, ?string $knownUuid = null): array
    {
        ['name' => $name, 'uuid' => $uuid, 'world' => $world] = $this->resolve($server, $name, $knownUuid);
        $raw = null;
        foreach (['/stats/', '/players/stats/'] as $folder) {
            try {
                $raw = $this->files->read($server, '/' . $world . $folder . $uuid . '.json', 2097152);
                break;
            } catch (DaemonConnectionException $exception) {
                if (!in_array($exception->getStatusCode(), [404, 500], true)) {
                    throw $exception;
                }
            }
        }
        if ($raw === null) {
            throw new HttpException(404, 'No saved stats were found. The player may not have joined yet.');
        }
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(422, 'The saved stats file is not valid JSON.');
        }

        return ['player' => $name, 'uuid' => $uuid, 'stats' => is_array($data['stats'] ?? null) ? $data['stats'] : []];
    }

    public function read(Server $server, string $name, ?string $knownUuid = null): array
    {
        ['name' => $name, 'uuid' => $uuid, 'world' => $world] = $this->resolve($server, $name, $knownUuid);
        $paths = [
            '/' . $world . '/playerdata/' . $uuid . '.dat',
            '/' . $world . '/players/data/' . $uuid . '.dat',
        ];
        $content = null;
        $savedPath = null;
        foreach ($paths as $path) {
            try {
                $content = $this->files->read($server, $path, 2097152);
                $savedPath = $path;
                break;
            } catch (DaemonConnectionException $exception) {
                // Wings reports missing files as 500 on some versions, including
                // when a server uses a non-standard player data directory.
                if (!in_array($exception->getStatusCode(), [404, 500], true)) {
                    throw $exception;
                }
            }
        }
        if ($content === null) {
            throw new HttpException(404, 'No saved inventory was found. The player may not have joined yet, or their UUID is missing from usercache.json.');
        }
        try {
            $snapshot = $this->snapshots->fromNbt($this->reader->parse($content));
        } catch (\UnexpectedValueException $exception) {
            throw new HttpException(422, 'The saved player data is invalid, unsupported or exceeds the inspection limits.', $exception);
        }

        return $snapshot + [
            'source' => 'saved_file', 'player' => $name, 'uuid' => $uuid,
            'saved_at' => $this->savedTime($server, $savedPath),
            'read_at' => now()->toIso8601String(),
            'state' => [
                'operator' => $this->listed($server, '/ops.json', $name, $uuid),
                'banned' => $this->listed($server, '/banned-players.json', $name, $uuid),
                'whitelisted' => $this->listed($server, '/whitelist.json', $name, $uuid),
            ],
        ];
    }

    public function world(string $properties): string
    {
        preg_match('/^\s*level-name\s*[=:]\s*([^\r\n]*)/m', $properties, $matches);
        $world = trim($matches[1] ?? 'world');
        $world = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', fn ($match) => mb_chr(hexdec($match[1]), 'UTF-8'), $world);
        $world = preg_replace_callback('/\\\\(.)/s', fn ($match) => match ($match[1]) {
            't' => "\t", 'n' => "\n", 'r' => "\r", 'f' => "\f", default => $match[1],
        }, $world);
        if ($world === '' || strlen($world) > 240 || str_starts_with($world, '/') || str_contains($world, '\\')
            || str_contains($world, ':') || preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $world)
            || array_intersect(explode('/', $world), ['', '.', '..']) !== []) {
            throw new HttpException(422, 'The configured world path cannot be inspected safely.');
        }

        return $world;
    }

    public function offlineUuid(string $name): string
    {
        $hex = md5('OfflinePlayer:' . $name);
        $hex[12] = '3';
        $hex[16] = dechex((hexdec($hex[16]) & 3) | 8);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function savedTime(Server $server, string $path): ?string
    {
        try {
            foreach ($this->files->directory($server, dirname($path)) as $entry) {
                if (($entry['name'] ?? null) === basename($path) && is_string($entry['modified'] ?? null)
                    && strtotime($entry['modified']) !== false) {
                    return $entry['modified'];
                }
            }
        } catch (DaemonConnectionException) {
            // A readable file remains inspectable if Wings cannot provide directory metadata.
        }

        return null;
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $value) === 1;
    }

    private function optionalJson(Server $server, string $path): ?array
    {
        try {
            $value = json_decode($this->files->read($server, $path, 2097152), true, 16, JSON_THROW_ON_ERROR);

            return is_array($value) && array_is_list($value) ? array_slice($value, 0, 10000) : null;
        } catch (DaemonConnectionException|\JsonException|HttpException) {
            return null;
        }
    }

    private function listed(Server $server, string $path, string $name, string $uuid): ?bool
    {
        $entries = $this->optionalJson($server, $path);
        if ($entries === null) {
            return null;
        }

        foreach ($entries as $entry) {
            if (is_array($entry)
                && ((isset($entry['uuid']) && strcasecmp((string) $entry['uuid'], $uuid) === 0)
                    || (isset($entry['name']) && strcasecmp((string) $entry['name'], $name) === 0))) {
                return true;
            }
        }

        return false;
    }
}
