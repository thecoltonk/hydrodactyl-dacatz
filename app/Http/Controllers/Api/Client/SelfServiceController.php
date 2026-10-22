<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Requests\Api\Client\Account\SelfService\CreatePlanServerRequest;
use Pterodactyl\Jobs\Server\InstallModpackJob;
use Pterodactyl\Services\Modpacks\CurseForgeModpacks;
use Pterodactyl\Services\Modpacks\ModpackPlanner;
use Pterodactyl\Services\Modpacks\ModrinthModpacks;
use Pterodactyl\Services\SelfService\PlanService;
use Pterodactyl\Services\ServerOperations\ServerOperationService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The signed-in user's server plan and self-service server creation
 * (from a game or straight from a modpack).
 */
class SelfServiceController extends ClientApiController
{
    public function __construct(
        private PlanService $plans,
        private ModpackPlanner $planner,
        private ServerOperationService $operations,
        private ModrinthModpacks $modrinth,
        private CurseForgeModpacks $curseforge,
    ) {
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
            'curseforge' => $plan !== null && $this->curseforge->configured(),
        ]);
    }

    public function store(CreatePlanServerRequest $request): JsonResponse
    {
        $user = $request->user();
        $modpack = $request->input('modpack');

        // Resolve the modpack first so a bad pack fails before a server exists.
        $pack = null;
        if (is_array($modpack)) {
            $this->requirePlan($request);
            $pack = $this->planner->plan((string) $modpack['source'], (string) $modpack['project_id'], (string) $modpack['version_id']);
        }

        $server = $this->plans->create(
            $user,
            trim((string) $request->input('name')),
            (int) $request->input('egg_id', 0),
            (int) $request->input('memory'),
            (int) $request->input('cpu'),
            (int) $request->input('disk'),
            $pack === null ? null : [
                'egg_id' => (int) $pack['egg_id'],
                'docker_image' => (string) $pack['docker_image'],
                'environment' => (array) $pack['environment'],
            ],
        );

        Activity::event('user:server.self-service-create')
            ->property(['server' => $server->name, 'uuid' => $server->uuid, 'modpack' => $pack === null ? null : $pack['title'] . ' ' . $pack['version_name']])
            ->log();

        $operationId = null;
        if ($pack !== null) {
            $operation = $this->operations->createOperation($server, $user, 'modpack_install', [
                'project_id' => $pack['project_id'],
                'version_id' => $pack['version_id'],
                'source' => $pack['source'],
                'title' => $pack['title'],
                'version_name' => $pack['version_name'],
                'loader' => $pack['loader'],
                'minecraft' => $pack['minecraft'],
            ], 'Queued modpack install...');
            $operationId = $operation->operation_id;
            InstallModpackJob::dispatch($server, $user, $pack, $operationId, true);
        }

        return response()->json([
            'identifier' => $server->uuidShort,
            'uuid' => $server->uuid,
            'operation_id' => $operationId,
        ], 201);
    }

    public function modpackSearch(Request $request): JsonResponse
    {
        $this->requirePlan($request);
        $data = $request->validate([
            'source' => ['nullable', 'in:modrinth,curseforge'],
            'query' => ['nullable', 'string', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:5000'],
        ]);

        return new JsonResponse($this->source($data['source'] ?? null)->search((string) ($data['query'] ?? ''), (int) ($data['offset'] ?? 0), null));
    }

    public function modpackVersions(Request $request): JsonResponse
    {
        $this->requirePlan($request);
        $data = $request->validate([
            'source' => ['nullable', 'in:modrinth,curseforge'],
            'project_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{1,64}\z/'],
        ]);

        return new JsonResponse(['versions' => $this->source($data['source'] ?? null)->versions($data['project_id'])]);
    }

    private function source(?string $source): ModrinthModpacks|CurseForgeModpacks
    {
        return $source === 'curseforge' ? $this->curseforge : $this->modrinth;
    }

    private function requirePlan(Request $request): void
    {
        if ($this->plans->plan($request->user()) === null) {
            throw new HttpException(403, 'Your account does not have a server plan.');
        }
    }
}
