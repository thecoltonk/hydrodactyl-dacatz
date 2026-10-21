<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\QuickBuild\DeletePlanServerRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\QuickBuild\UpdateQuickBuildRequest;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\SelfService\PlanService;
use Pterodactyl\Services\Servers\BuildModificationService;
use Pterodactyl\Services\Servers\ServerDeletionService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The pencil on the server list: resize (CPU/RAM/storage) and delete. Panel
 * admins are unrestricted; plan owners stay inside their plan.
 */
class QuickBuildController extends ClientApiController
{
    public function __construct(
        private BuildModificationService $builds,
        private PlanService $plans,
        private ServerDeletionService $deletion,
    ) {
        parent::__construct();
    }

    public function update(UpdateQuickBuildRequest $request, Server $server): JsonResponse
    {
        $user = $request->user();
        $before = ['memory' => $server->memory, 'cpu' => $server->cpu, 'disk' => $server->disk];
        $memory = (int) $request->input('memory');
        $cpu = (int) $request->input('cpu');
        $disk = (int) $request->input('disk', $server->disk);

        $lock = Cache::lock('self-service:user:' . $server->owner_id, 60);
        if (!$lock->block(15)) {
            throw new HttpException(429, 'Another change to these servers is in progress. Try again in a moment.');
        }
        try {
            if (!$user->root_admin) {
                $plan = $this->plans->plan($user);
                if ($plan === null) {
                    throw new HttpException(403, 'Your account does not have a server plan.');
                }
                $this->plans->assertFits($plan, $user, $memory, $cpu, $disk, $server);
            }

            // BuildModificationService resets these limits when they're missing,
            // so pass the current values straight back.
            $server = $this->builds->handle($server, [
                'memory' => $memory,
                'cpu' => $cpu,
                'disk' => $disk,
                'database_limit' => $server->database_limit,
                'allocation_limit' => $server->allocation_limit,
                'backup_limit' => $server->backup_limit,
                'backup_storage_limit' => $server->backup_storage_limit,
            ]);
        } finally {
            $lock->release();
        }

        Activity::event('server:settings.quick-build')
            ->property(['old' => $before, 'new' => ['memory' => $memory, 'cpu' => $cpu, 'disk' => $disk]])
            ->log();

        return response()->json(['memory' => $server->memory, 'cpu' => $server->cpu, 'disk' => $server->disk]);
    }

    public function destroy(DeletePlanServerRequest $request, Server $server): JsonResponse
    {
        if (trim((string) $request->input('confirm_name')) !== $server->name) {
            throw new HttpException(422, 'Type the server name exactly to delete it.');
        }

        Activity::event('user:server.self-service-delete')
            ->property(['server' => $server->name, 'uuid' => $server->uuid])
            ->log();

        $this->deletion->handle($server);

        return response()->json([], 204);
    }
}
