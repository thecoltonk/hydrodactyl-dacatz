<?php

/*
 * Player list, moderation actions and saved-inventory snapshots for Minecraft
 * Java servers. Ported from Aquadactyl/MCPlayersJava
 * (https://github.com/Aquadactyl/MCPlayersJava), Copyright (c) Aquadactyl
 * contributors, BSD 2-Clause License; see licenses/MCPlayersJava.LICENSE.
 */

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers\GetInventoryRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers\GetPlayersRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers\PlayerActionRequest;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\MinecraftPlayers\InventoryService;
use Pterodactyl\Services\MinecraftPlayers\PlayerCommand;
use Pterodactyl\Services\MinecraftPlayers\PlayerFiles;
use Pterodactyl\Services\MinecraftPlayers\PlayerTarget;
use Pterodactyl\Services\MinecraftPlayers\ServerQuery;

class MinecraftPlayersController extends ClientApiController
{
    public function __construct(
        private PlayerTarget $targets,
        private PlayerFiles $files,
        private ServerQuery $query,
    ) {
        parent::__construct();
    }

    /**
     * List the players currently reported online.
     */
    public function index(GetPlayersRequest $request, Server $server): array
    {
        $capabilities = $this->capabilities($request->user(), $server);
        $empty = ['status' => 'unsupported', 'source' => null, 'complete' => false, 'online' => null, 'maximum' => null, 'players' => [], 'checked_at' => null];
        if (!$this->targets->supports($server)) {
            return ['object' => 'minecraft_player_list', 'attributes' => $empty + ['capabilities' => $capabilities]];
        }

        $key = $this->cacheKey($server);
        $result = Cache::get($key);
        if ($result === null) {
            $lock = Cache::lock($key . ':lock', 8);
            if ($lock->get()) {
                try {
                    // Recheck after acquiring the lock to avoid duplicate queries.
                    $result = Cache::get($key);
                    if ($result === null) {
                        $target = $this->targets->resolve($server, $this->properties($server));
                        $result = $target === null ? ServerQuery::unavailable() : $this->query->players($target);
                        $result['checked_at'] = now()->toIso8601String();
                        Cache::put($key, $result, 10);
                    }
                } finally {
                    $lock->release();
                }
            } else {
                $result = array_replace($empty, ['status' => 'pending']);
            }
        }

        // Capabilities belong to the requesting user, never the shared cache.
        return ['object' => 'minecraft_player_list', 'attributes' => $result + ['capabilities' => $capabilities]];
    }

    /**
     * Send a validated moderation command (kick, ban, op, whitelist...).
     */
    public function action(PlayerActionRequest $request, Server $server, PlayerCommand $commands): array
    {
        $data = $request->validated();
        $command = $commands->build($data['player'], $data['action'], $data['reason'] ?? null);
        $this->files->command($server, $command);

        Activity::event('server:console.command')
            ->property('command', $command)
            ->property('player_action', $data['action'])
            ->log();
        Cache::forget($this->cacheKey($server));

        // The daemon acknowledges submission; Minecraft's console reports the result.
        return ['object' => 'minecraft_player_action', 'attributes' => ['status' => 'submitted', 'action' => $data['action'], 'player' => $data['player']]];
    }

    /**
     * Read-only snapshot of a player's last saved inventory.
     */
    public function inventory(GetInventoryRequest $request, Server $server, InventoryService $inventories): array
    {
        $data = $request->validated();

        return ['object' => 'minecraft_inventory_snapshot', 'attributes' => $inventories->read($server, $data['player'], $data['uuid'] ?? null)];
    }

    private function capabilities(User $user, Server $server): array
    {
        if (!$this->targets->supports($server)) {
            return ['moderate' => false, 'operator' => false, 'inventory' => false];
        }
        $console = $user->can(Permission::ACTION_CONTROL_CONSOLE, $server);

        return [
            'moderate' => $console,
            'operator' => $console && ($user->root_admin || $user->id === $server->owner_id),
            'inventory' => $console && $user->can(Permission::ACTION_FILE_READ, $server)
                && $user->can(Permission::ACTION_FILE_READ_CONTENT, $server),
        ];
    }

    private function properties(Server $server): ?string
    {
        try {
            return $this->files->read($server, '/server.properties', 65536);
        } catch (DaemonConnectionException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            return null;
        }
    }

    private function cacheKey(Server $server): string
    {
        return 'minecraft-players:v1:' . $server->uuid;
    }
}
