<?php

namespace Pterodactyl\Services\MinecraftPlayers;

use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;

/**
 * Applies panel-uploaded skins in game through whichever skin mod/plugin the
 * server runs. Minecraft itself has no way to set a skin on offline servers.
 *
 *  - SkinRestorer (Suiranoil) — Fabric/Quilt/Forge/NeoForge mod
 *  - SkinsRestorer (SRTeam)   — Paper/Spigot plugin
 */
class SkinCommands
{
    public function __construct(private PlayerFiles $files)
    {
    }

    /**
     * 'skinrestorer' | 'skinsrestorer' | null
     */
    public function provider(Server $server): ?string
    {
        foreach ($this->files->names($server, '/mods') as $name) {
            if (preg_match('/skinrestorer.*\.jar$/i', $name)) {
                return 'skinrestorer';
            }
        }
        foreach ($this->files->names($server, '/plugins') as $name) {
            if (preg_match('/skinsrestorer.*\.jar$/i', $name)) {
                return 'skinsrestorer';
            }
        }

        return null;
    }

    /**
     * @return array{applied: bool, provider: string|null, message: string}
     */
    public function apply(Server $server, string $name, string $url, string $variant): array
    {
        $provider = $this->provider($server);
        if ($provider === null) {
            return [
                'applied' => false,
                'provider' => null,
                'message' => 'Skin saved, but no skin mod is installed. Add the "SkinRestorer" mod (Fabric/Forge/NeoForge/Quilt) or the "SkinsRestorer" plugin (Paper), restart, then press Apply.',
            ];
        }
        $variant = $variant === 'slim' ? 'slim' : 'classic';
        $command = $provider === 'skinrestorer'
            ? sprintf('skin set web %s "%s" %s', $variant, $url, $name)
            : sprintf('skin set %s %s %s', $url, $name, $variant);

        return $this->send($server, $command, $provider, 'Skin sent to the server. The player must be online; if they were not, press Apply once they join.');
    }

    public function clear(Server $server, string $name): array
    {
        $provider = $this->provider($server);
        if ($provider === null) {
            return ['applied' => false, 'provider' => null, 'message' => 'Skin removed from the panel.'];
        }

        return $this->send($server, 'skin clear ' . $name, $provider, 'Skin removed and cleared in game.');
    }

    private function send(Server $server, string $command, string $provider, string $message): array
    {
        try {
            $this->files->command($server, $command);
        } catch (DaemonConnectionException) {
            return ['applied' => false, 'provider' => $provider, 'message' => 'Skin saved. Start the server and press Apply while the player is online.'];
        }

        return ['applied' => true, 'provider' => $provider, 'message' => $message, 'command' => $command];
    }
}
