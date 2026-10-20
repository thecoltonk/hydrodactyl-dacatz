<?php

namespace Pterodactyl\Services\MinecraftPlayers;

use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Offline-mode ("cracked") server helpers.
 *
 * Offline UUIDs are derived from the exact, case-sensitive username. Vanilla's
 * `whitelist add` first asks Mojang for the profile; when the name has no
 * Mojang account it falls back to the *lowercased* name, so a player called
 * "_Kfcz" gets whitelisted as "_kfcz" (a different UUID) and still can't join.
 * Writing whitelist.json ourselves with the correct UUID avoids that.
 */
class OfflineModeSupport
{
    public function __construct(private PlayerFiles $files)
    {
    }

    /**
     * Null when server.properties cannot be read.
     */
    public function isOffline(Server $server): ?bool
    {
        try {
            $properties = $this->files->read($server, '/server.properties', 65536);
        } catch (DaemonConnectionException|HttpException) {
            return null;
        }

        return app(PlayerTarget::class)->property($properties, 'online-mode') === 'false';
    }

    public static function uuid(string $name): string
    {
        $hex = md5('OfflinePlayer:' . $name);
        $hex[12] = '3';
        $hex[16] = dechex((hexdec($hex[16]) & 3) | 8);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    /**
     * Add or remove a player in whitelist.json, then ask a running server to reload it.
     * Entries for the same name with a wrong-case UUID are cleaned up as well.
     */
    public function whitelist(Server $server, string $name, bool $add): void
    {
        try {
            $raw = $this->files->read($server, '/whitelist.json', 2097152);
            $entries = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (DaemonConnectionException|\JsonException) {
            $entries = [];
        }
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new HttpException(422, 'whitelist.json is not a valid list; fix it in the file manager first.');
        }

        $uuid = self::uuid($name);
        $entries = array_values(array_filter($entries, fn ($entry) => is_array($entry)
            && strcasecmp((string) ($entry['name'] ?? ''), $name) !== 0
            && strcasecmp((string) ($entry['uuid'] ?? ''), $uuid) !== 0));
        if ($add) {
            $entries[] = ['uuid' => $uuid, 'name' => $name];
        }

        $this->files->write($server, '/whitelist.json', json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        try {
            $this->files->command($server, 'whitelist reload');
        } catch (DaemonConnectionException) {
            // Server is stopped; the file is read on next start.
        }
    }
}
