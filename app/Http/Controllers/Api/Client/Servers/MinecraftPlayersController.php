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
use Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers\EditInventoryRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers\GetInventoryRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers\KnownPlayersRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers\GetPlayersRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers\PlayerActionRequest;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers\SkinRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers\UploadSkinRequest;
use Pterodactyl\Services\MinecraftPlayers\InventoryEditor;
use Pterodactyl\Services\MinecraftPlayers\InventoryService;
use Pterodactyl\Services\MinecraftPlayers\OfflineModeSupport;
use Pterodactyl\Services\MinecraftPlayers\SkinCommands;
use Pterodactyl\Services\MinecraftPlayers\SkinStore;
use Pterodactyl\Services\MinecraftPlayers\StatsSnapshot;
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
                        $properties = $this->properties($server);
                        $target = $this->targets->resolve($server, $properties);
                        $result = $target === null ? ServerQuery::unavailable() : $this->query->players($target);
                        $result['checked_at'] = now()->toIso8601String();
                        $result['online_mode'] = $properties === null
                            ? null
                            : $this->targets->property($properties, 'online-mode') !== 'false';
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
        return ['object' => 'minecraft_player_list', 'attributes' => $result + [
            'capabilities' => $capabilities,
            'skins' => (object) app(SkinStore::class)->all($server),
        ]];
    }

    /**
     * Send a validated moderation command (kick, ban, op, whitelist...).
     */
    public function action(PlayerActionRequest $request, Server $server, PlayerCommand $commands): array
    {
        $data = $request->validated();
        try {
            $command = $commands->build($data['player'], $data['action'], $data['reason'] ?? null, $data);
        } catch (\InvalidArgumentException $exception) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(422, $exception->getMessage());
        }

        $offline = app(OfflineModeSupport::class);
        if (str_starts_with($data['action'], 'whitelist_') && $offline->isOffline($server)) {
            // Offline mode: write the exact-case offline UUID ourselves (see OfflineModeSupport).
            $offline->whitelist($server, $data['player'], $data['action'] === 'whitelist_add');
            $command = 'whitelist.json ' . ($data['action'] === 'whitelist_add' ? 'add ' : 'remove ') . $data['player'];
        } else {
            $this->files->command($server, $command);
        }

        Activity::event('server:console.command')
            ->property('command', $command)
            ->property('player_action', $data['action'])
            ->log();
        Cache::forget($this->cacheKey($server));

        // The daemon acknowledges submission; Minecraft's console reports the result.
        return ['object' => 'minecraft_player_action', 'attributes' => ['status' => 'submitted', 'action' => $data['action'], 'player' => $data['player']]];
    }

    /**
     * Upload a custom skin for a player (offline-mode servers) and apply it in game.
     */
    public function uploadSkin(UploadSkinRequest $request, Server $server, SkinStore $skins, SkinCommands $commands): array
    {
        $data = $request->validated();
        $url = $skins->put($server, $data['player'], (string) $request->file('skin')->get(), $data['variant']);
        $result = $commands->apply($server, $data['player'], $url, $data['variant']);

        Activity::event('server:players.skin-uploaded')->property(['player' => $data['player'], 'variant' => $data['variant']])->log();

        return ['object' => 'minecraft_player_skin', 'attributes' => $result + ['url' => $url, 'variant' => $data['variant']]];
    }

    /**
     * Re-send a stored skin to the server (e.g. once the player is online).
     */
    public function applySkin(SkinRequest $request, Server $server, SkinStore $skins, SkinCommands $commands): array
    {
        $player = $request->validated()['player'];
        $skin = $skins->find($server, $player);
        if ($skin === null) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(404, 'No custom skin is stored for this player.');
        }

        return ['object' => 'minecraft_player_skin', 'attributes' => $commands->apply($server, $skin['name'], $skin['url'], $skin['variant']) + $skin];
    }

    public function deleteSkin(SkinRequest $request, Server $server, SkinStore $skins, SkinCommands $commands): array
    {
        $player = $request->validated()['player'];
        $skins->forget($server, $player);

        return ['object' => 'minecraft_player_skin', 'attributes' => $commands->clear($server, $player)];
    }

    /**
     * Read-only snapshot of a player's last saved inventory.
     */
    public function inventory(GetInventoryRequest $request, Server $server, InventoryService $inventories): array
    {
        $data = $request->validated();
        $this->saveFirst($request, $server);

        return ['object' => 'minecraft_inventory_snapshot', 'attributes' => $inventories->read($server, $data['player'], $data['uuid'] ?? null)];
    }

    /**
     * Player statistics (playtime, kills, distance, blocks mined...).
     */
    public function stats(GetInventoryRequest $request, Server $server, InventoryService $inventories, StatsSnapshot $snapshots): array
    {
        $data = $request->validated();
        $this->saveFirst($request, $server);
        $raw = $inventories->stats($server, $data['player'], $data['uuid'] ?? null);

        return ['object' => 'minecraft_player_stats', 'attributes' => [
            'player' => $raw['player'],
            'uuid' => $raw['uuid'],
            'read_at' => now()->toIso8601String(),
        ] + $snapshots->summarize($raw['stats'])];
    }

    /**
     * Optionally flush the running server's player data to disk first, so the
     * snapshot is current. Silently skipped when the server is offline.
     */
    /**
     * Everyone with saved player data (online or not), for the "show offline
     * players" list.
     */
    public function known(KnownPlayersRequest $request, Server $server, InventoryService $inventories): array
    {
        $players = Cache::remember('minecraft-players:known:' . $server->uuid, 30, fn () => $inventories->known($server));

        return ['object' => 'minecraft_known_players', 'attributes' => ['players' => $players]];
    }

    /**
     * Edit inventory / ender chest slots. Online players get /item commands;
     * offline players have their save file rewritten.
     */
    public function editInventory(EditInventoryRequest $request, Server $server, InventoryEditor $editor): array
    {
        $data = $request->validated();
        $changes = array_map(fn (array $change) => [
            'area' => (string) $change['area'],
            'slot' => (int) $change['slot'],
            'id' => isset($change['id']) ? (string) $change['id'] : null,
            'count' => isset($change['count']) ? (int) $change['count'] : null,
            'expect' => isset($change['expect']) ? (string) $change['expect'] : null,
        ], $data['changes']);

        $online = $this->isOnline($server, $data['player']);
        if ($online === null) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'Can\'t tell whether ' . $data['player'] . ' is online: the server status ping only lists 12 names. Enable query in server.properties (see the bottom of the Players page) or stop the server, then try again.');
        }
        $result = $editor->apply($server, $data['player'], $data['uuid'] ?? null, $changes, $online);

        Activity::event('server:players.inventory-edited')
            ->property(['player' => $data['player'], 'mode' => $result['mode'], 'changes' => count($changes)])
            ->log();

        return ['object' => 'minecraft_inventory_edit', 'attributes' => $result + [
            'message' => $result['mode'] === 'online'
                ? 'Sent to the server. The player is online, so changes apply immediately.'
                : 'Saved to the player file. They will have it next time they join.',
        ]];
    }

    /**
     * Whether the player shows up in a fresh player list (null = can't tell).
     * If the server can't be reached it is stopped, so the save file is safe.
     */
    private function isOnline(Server $server, string $name): ?bool
    {
        Cache::forget($this->cacheKey($server));
        $properties = $this->properties($server);
        $target = $this->targets->resolve($server, $properties);
        if ($target === null) {
            return false;
        }
        $result = $this->query->players($target);
        foreach ($result['players'] ?? [] as $player) {
            if (strcasecmp((string) ($player['name'] ?? ''), $name) === 0) {
                return true;
            }
        }
        // A partial status sample can miss people, so we can't be sure.
        $partial = ($result['status'] ?? null) === 'available' && !($result['complete'] ?? false)
            && (int) ($result['online'] ?? 0) > count($result['players'] ?? []);

        return $partial ? null : false;
    }

    private function saveFirst(GetInventoryRequest $request, Server $server): void
    {
        if (!$request->boolean('save')) {
            return;
        }
        try {
            $this->files->command($server, 'save-all');
            sleep(2);
        } catch (DaemonConnectionException) {
            // Offline: files on disk are already the latest.
        }
    }

    private function capabilities(User $user, Server $server): array
    {
        if (!$this->targets->supports($server)) {
            return ['moderate' => false, 'operator' => false, 'inventory' => false, 'edit_inventory' => false];
        }
        $console = $user->can(Permission::ACTION_CONTROL_CONSOLE, $server);

        return [
            'moderate' => $console,
            'operator' => $console && ($user->root_admin || $user->id === $server->owner_id),
            'inventory' => $console && $user->can(Permission::ACTION_FILE_READ, $server)
                && $user->can(Permission::ACTION_FILE_READ_CONTENT, $server),
            'edit_inventory' => $console && $user->can(Permission::ACTION_FILE_READ, $server)
                && $user->can(Permission::ACTION_FILE_READ_CONTENT, $server)
                && $user->can(Permission::ACTION_FILE_UPDATE, $server),
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
