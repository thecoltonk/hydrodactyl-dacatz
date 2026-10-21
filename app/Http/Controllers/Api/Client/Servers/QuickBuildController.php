<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\QuickBuild\UpdateQuickBuildRequest;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Servers\BuildModificationService;

/**
 * Lets panel admins change a server's CPU and RAM straight from the server
 * list (the pencil button). Everything else about the build stays as-is.
 */
class QuickBuildController extends ClientApiController
{
    public function __construct(private BuildModificationService $builds)
    {
        parent::__construct();
    }

    public function update(UpdateQuickBuildRequest $request, Server $server): JsonResponse
    {
        $before = ['memory' => $server->memory, 'cpu' => $server->cpu];
        $memory = (int) $request->input('memory');
        $cpu = (int) $request->input('cpu');

        // BuildModificationService resets these limits when they're missing,
        // so pass the current values straight back.
        $server = $this->builds->handle($server, [
            'memory' => $memory,
            'cpu' => $cpu,
            'database_limit' => $server->database_limit,
            'allocation_limit' => $server->allocation_limit,
            'backup_limit' => $server->backup_limit,
            'backup_storage_limit' => $server->backup_storage_limit,
        ]);

        Activity::event('server:settings.quick-build')
            ->property(['old' => $before, 'new' => ['memory' => $memory, 'cpu' => $cpu]])
            ->log();

        return response()->json(['memory' => $server->memory, 'cpu' => $server->cpu]);
    }
}
