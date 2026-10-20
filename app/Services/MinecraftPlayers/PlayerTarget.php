<?php

namespace Pterodactyl\Services\MinecraftPlayers;

use Pterodactyl\Models\Server;

/**
 * Decides whether a server is a Minecraft Java game server and where the panel
 * should send status/Query packets for it.
 *
 * Only persisted, administrator-controlled data (allocations, node FQDN and the
 * server's own server.properties) chooses the host and port. Request input never
 * does, so the endpoint cannot be used to probe arbitrary hosts.
 */
class PlayerTarget
{
    private const PROXY_FEATURES = '~^plugin/(velocity|bungeecord|waterfall)$~i';

    private const JAVA_NAMES = '/minecraft|paper|purpur|spigot|forge|fabric|quilt|sponge|folia/i';

    private const NOT_JAVA_NAMES = '/bedrock|pocketmine|nukkit|velocity|bungee|waterfall|limbo/i';

    /**
     * Minecraft Java game servers only. Proxies have no player saves and do not
     * accept the vanilla moderation commands, so they are excluded.
     */
    public function supports(Server $server): bool
    {
        $features = array_filter((array) ($server->egg?->inherit_features ?? []), 'is_string');
        foreach ($features as $feature) {
            if (preg_match(self::PROXY_FEATURES, $feature)) {
                return false;
            }
        }
        if (in_array('eula', $features, true)) {
            return true;
        }

        // Eggs imported without Hydrodactyl feature flags fall back to the egg name.
        $name = (string) ($server->egg?->name ?? '');

        return !preg_match(self::NOT_JAVA_NAMES, $name) && (bool) preg_match(self::JAVA_NAMES, $name);
    }

    /**
     * @return array{statusHost: string, statusPort: int, queryHost: string, queryPort: int|null}|null
     */
    public function resolve(Server $server, ?string $properties): ?array
    {
        if (!$this->supports($server)) {
            return null;
        }
        $primary = $server->allocation;
        if (!$primary || (int) $primary->server_id !== (int) $server->id) {
            return null;
        }

        $host = $this->host($server, (string) $primary->ip);
        $queryPort = null;
        if ($properties !== null && $this->property($properties, 'enable-query') === 'true') {
            $configured = (int) ($this->property($properties, 'query.port') ?? $primary->port);
            // Query only ever goes to a port this server actually owns.
            $owned = $server->allocations()->where('port', $configured)->exists();
            $queryPort = $owned ? $configured : null;
        }

        return [
            'statusHost' => $host,
            'statusPort' => (int) $primary->port,
            'queryHost' => $host,
            'queryPort' => $queryPort,
        ];
    }

    public function property(string $properties, string $key): ?string
    {
        $pattern = '/^\s*' . preg_quote($key, '/') . '\s*[=:]\s*([^\r\n]*)/m';
        if (!preg_match($pattern, $properties, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    private function host(Server $server, string $ip): string
    {
        // Wildcard and loopback bindings are reachable through the node itself.
        if (in_array($ip, ['0.0.0.0', '::', '127.0.0.1', '::1'], true)) {
            return $server->node->getInternalFqdn();
        }

        return $ip;
    }
}
