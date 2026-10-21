<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Requests\Api\Client\Account\SelfService\CreatePlanServerRequest;
use Pterodactyl\Services\SelfService\PlanService;

/**
 * The signed-in user's server plan and self-service server creation.
 */
class SelfServiceController extends ClientApiController
{
    public function __construct(private PlanService $plans)
    {
        parent::__construct();
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $plan = $this->plans->plan($user);

        return response()->json([
            'plan' => $plan === null ? null : [
                'memory' => $plan->memory,
                'cpu' => $plan->cpu,
                'disk' => $plan->disk,
                'servers' => $plan->servers,
            ],
            'used' => $this->plans->usage($user),
            'minimum' => ['memory' => PlanService::MIN_MEMORY, 'cpu' => PlanService::MIN_CPU, 'disk' => PlanService::MIN_DISK],
            'eggs' => $plan === null ? [] : $this->plans->eggs(),
        ]);
    }

    public function store(CreatePlanServerRequest $request): JsonResponse
    {
        $server = $this->plans->create(
            $request->user(),
            trim((string) $request->input('name')),
            (int) $request->input('egg_id'),
            (int) $request->input('memory'),
            (int) $request->input('cpu'),
            (int) $request->input('disk'),
        );

        Activity::event('user:server.self-service-create')
            ->property(['server' => $server->name, 'uuid' => $server->uuid])
            ->log();

        return response()->json(['identifier' => $server->uuidShort, 'uuid' => $server->uuid], 201);
    }
}
