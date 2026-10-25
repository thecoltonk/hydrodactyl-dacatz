<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\CheckDaemonType;
use Pterodactyl\Http\Controllers\Api\Client;
use Pterodactyl\Http\Controllers\Api\Client\Servers\MarketplaceController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\MinecraftPlayersController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\ModpacksController;
use Pterodactyl\Http\Middleware\Activity\ServerSubject;
use Pterodactyl\Http\Middleware\Activity\AccountSubject;
use Pterodactyl\Http\Controllers\Api\Client\Servers\Elytra;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;

/*
|--------------------------------------------------------------------------
| Client Control API
|--------------------------------------------------------------------------
|
| Endpoint: /api/client
|
*/

Route::get('/', [Client\ClientController::class, 'index'])->name('api:client.index');
Route::get('/filter-options', [Client\ClientController::class, 'filterOptions'])->name('api:client.filter-options');
Route::get('/permissions', [Client\ClientController::class, 'permissions']);
Route::get('/version', function () {
    return response()->json(['version' => config('app.version')]);
});

Route::prefix('/server-groups')->group(function () {
    Route::get('/', [Client\ServerGroupController::class, 'index'])->name('api:client.server-groups.index');
    Route::post('/', [Client\ServerGroupController::class, 'store'])->name('api:client.server-groups.store');
    Route::put('/{serverGroup}', [Client\ServerGroupController::class, 'update'])->name('api:client.server-groups.update');
    Route::delete('/{serverGroup}', [Client\ServerGroupController::class, 'destroy'])->name('api:client.server-groups.destroy');
    Route::post('/{serverGroup}/servers', [Client\ServerGroupController::class, 'addServers'])->name('api:client.server-groups.servers.add');
    Route::delete('/{serverGroup}/servers', [Client\ServerGroupController::class, 'removeServers'])->name('api:client.server-groups.servers.remove');
    Route::put('/reorder', [Client\ServerGroupController::class, 'reorder'])->name('api:client.server-groups.reorder');
});

Route::prefix('/nests')->group(function () {
    Route::get('/', [Client\Nests\NestController::class, 'index'])->name('api:client.nests');
    Route::get('/{nest}', [Client\Nests\NestController::class, 'view'])->name('api:client.nests.view');
});

Route::prefix('/account')->middleware(AccountSubject::class)->group(function () {
    Route::prefix('/')->withoutMiddleware(RequireTwoFactorAuthentication::class)->group(function () {
        Route::get('/', [Client\AccountController::class, 'index'])->name('api:client.account');
        Route::get('/two-factor', [Client\TwoFactorController::class, 'index']);
        Route::post('/two-factor', [Client\TwoFactorController::class, 'store']);
        Route::post('/two-factor/disable', [Client\TwoFactorController::class, 'delete']);
    });

    Route::put('/email', [Client\AccountController::class, 'updateEmail'])->name('api:client.account.update-email');
    Route::put('/password', [Client\AccountController::class, 'updatePassword'])->name('api:client.account.update-password');

    Route::get('/activity', Client\ActivityLogController::class)->name('api:client.account.activity');

    Route::get('/discord', [Client\DiscordAccountController::class, 'show']);
    Route::delete('/discord', [Client\DiscordAccountController::class, 'destroy']);

    Route::get('/api-keys', [Client\ApiKeyController::class, 'index']);
    Route::post('/api-keys', [Client\ApiKeyController::class, 'store']);
    Route::delete('/api-keys/{identifier}', [Client\ApiKeyController::class, 'delete']);

    // Self-service servers inside the user's plan.
    Route::get('/plan', [Client\SelfServiceController::class, 'show']);
    Route::post('/plan/servers', [Client\SelfServiceController::class, 'store'])->middleware('throttle:3,10,self-service-create');
    Route::get('/plan/modpacks/search', [Client\SelfServiceController::class, 'modpackSearch'])->middleware('throttle:40,1,self-service-modpacks');
    Route::get('/plan/modpacks/versions', [Client\SelfServiceController::class, 'modpackVersions'])->middleware('throttle:40,1,self-service-modpacks');

    // Private mod/plugin collections for one-click installs.
    Route::prefix('/mod-collections')->middleware('throttle:60,1,mod-collections')->group(function () {
        Route::get('/', [Client\ModCollectionsController::class, 'index']);
        Route::post('/', [Client\ModCollectionsController::class, 'store']);
        Route::patch('/{collection}', [Client\ModCollectionsController::class, 'update'])->whereNumber('collection');
        Route::delete('/{collection}', [Client\ModCollectionsController::class, 'destroy'])->whereNumber('collection');
        Route::post('/{collection}/items', [Client\ModCollectionsController::class, 'addItem'])->whereNumber('collection');
        Route::delete('/{collection}/items', [Client\ModCollectionsController::class, 'removeItem'])->whereNumber('collection');
    });

    Route::prefix('/ssh-keys')->group(function () {
        Route::get('/', [Client\SSHKeyController::class, 'index']);
        Route::post('/', [Client\SSHKeyController::class, 'store']);
        Route::post('/remove', [Client\SSHKeyController::class, 'delete']);
    });
});

/*
|--------------------------------------------------------------------------
| Client Control API
|--------------------------------------------------------------------------
|
| Endpoint: /api/client/servers/{server}
|
*/

Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [
        ServerSubject::class,
        AuthenticateServerAccess::class,
        ResourceBelongsToServer::class,
    ],
], function () {
    Route::get('/', [Client\ServerController::class, 'index'])->name('api.client.servers.daemonType');
    Route::get('/resources', [Client\ServerController::class, 'resources'])->name('api.client.servers.resources');

    Route::group(['prefix' => '/subdomain'], function () {
        Route::get('/', [Elytra\SubdomainController::class, 'index']);
        Route::post('/', [Elytra\SubdomainController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::delete('/', [Elytra\SubdomainController::class, 'destroy'])
            ->middleware('throttle:10,1');
        Route::post('/check-availability', [Elytra\SubdomainController::class, 'checkAvailability'])
            ->middleware('throttle:25,1');
    });

    // Native plugin/mod installer (Minecraft). Browse/search are cached and
    // throttled; the actual file transfer happens via the per-daemon pull.
    Route::group(['prefix' => '/marketplace', 'middleware' => ['throttle:30,1']], function () {
        Route::get('/search', [MarketplaceController::class, 'search'])->name('api.client.servers.marketplace.search');
        Route::get('/project', [MarketplaceController::class, 'project'])->name('api.client.servers.marketplace.project');
        // Resolve yields a daemon-pulled download URL, so it gets a tighter
        // limiter than browse (caps upstream amplification per user).
        Route::get('/resolve', [MarketplaceController::class, 'resolve'])
            ->name('api.client.servers.marketplace.resolve');

        // Install history. Stored in the panel database (not on the daemon) so
        // it stays private — invisible in the file manager and out of backups.
        Route::get('/installed', [MarketplaceController::class, 'installed'])
            ->name('api.client.servers.marketplace.installed');
        Route::post('/installed', [MarketplaceController::class, 'store']);
        Route::delete('/installed', [MarketplaceController::class, 'destroy']);

        // Cached Modrinth loader-tag list, so egg-feature loaders are validated
        // against the live set instead of a hard-coded list.
        Route::get('/loaders', [MarketplaceController::class, 'loaders'])
            ->name('api.client.servers.marketplace.loaders');

        Route::get('/game-versions', [MarketplaceController::class, 'gameVersions'])
            ->name('api.client.servers.marketplace.game-versions');
    });

    // CPU/RAM/storage edit and delete from the server list (admins, or plan owners).
    Route::patch('/quick-build', [Client\Servers\QuickBuildController::class, 'update'])
        ->middleware('throttle:20,1,quick-build');
    Route::delete('/self-service', [Client\Servers\QuickBuildController::class, 'destroy'])
        ->middleware('throttle:5,1,self-service-delete');

    // One-click Modrinth modpack installs (fresh install).
    Route::group(['prefix' => '/modpacks'], function () {
        Route::get('/search', [ModpacksController::class, 'search'])
            ->middleware('throttle:40,1,mc-modpacks-browse');
        Route::get('/versions', [ModpacksController::class, 'versions'])
            ->middleware('throttle:40,1,mc-modpacks-browse');
        Route::get('/status', [ModpacksController::class, 'status'])
            ->middleware('throttle:60,1,mc-modpacks-status');
        Route::post('/install', [ModpacksController::class, 'install'])
            ->middleware('throttle:3,1,mc-modpacks-install');
    });

    // Minecraft Java player list, moderation actions and saved inventories.
    Route::group(['prefix' => '/players'], function () {
        Route::get('/', [MinecraftPlayersController::class, 'index'])
            ->middleware('throttle:20,1,mc-players')
            ->name('api.client.servers.players');
        Route::post('/{player}/actions', [MinecraftPlayersController::class, 'action'])
            ->where('player', '[A-Za-z0-9_]{1,16}')
            ->middleware('throttle:10,1,mc-players-actions')
            ->name('api.client.servers.players.actions');
        Route::get('/{player}/inventory', [MinecraftPlayersController::class, 'inventory'])
            ->where('player', '[A-Za-z0-9_]{1,16}')
            ->middleware('throttle:12,1,mc-players-inventory')
            ->name('api.client.servers.players.inventory');
        Route::get('/known', [MinecraftPlayersController::class, 'known'])
            ->middleware('throttle:20,1,mc-players-known');
        Route::put('/{player}/inventory', [MinecraftPlayersController::class, 'editInventory'])
            ->where('player', '[A-Za-z0-9_]{1,16}')
            ->middleware('throttle:20,1,mc-players-inventory-edit');
        Route::get('/{player}/stats', [MinecraftPlayersController::class, 'stats'])
            ->where('player', '[A-Za-z0-9_]{1,16}')
            ->middleware('throttle:12,1,mc-players-stats');
        Route::post('/{player}/skin', [MinecraftPlayersController::class, 'uploadSkin'])
            ->where('player', '[A-Za-z0-9_]{1,16}')
            ->middleware('throttle:10,1,mc-players-skins');
        Route::post('/{player}/skin/apply', [MinecraftPlayersController::class, 'applySkin'])
            ->where('player', '[A-Za-z0-9_]{1,16}')
            ->middleware('throttle:10,1,mc-players-skins');
        Route::delete('/{player}/skin', [MinecraftPlayersController::class, 'deleteSkin'])
            ->where('player', '[A-Za-z0-9_]{1,16}')
            ->middleware('throttle:10,1,mc-players-skins');
    });
});



/*
|--------------------------------------------------------------------------
| Client Control API(Wings)
|--------------------------------------------------------------------------
|
| Endpoint: /api/client/servers/wings/{server}
|
*/

Route::group([
    'prefix' => 'servers/wings',
], function () {
    require __DIR__ . '/servers/wings.php';
});


/*
|--------------------------------------------------------------------------
| Client Control API(Elytra)
|--------------------------------------------------------------------------
|
| Endpoint: /api/client/servers/elytra/{server}
|
*/
Route::group([
    'prefix' => 'servers/elytra',
], function () {
    require __DIR__ . '/servers/elytra.php';
});
