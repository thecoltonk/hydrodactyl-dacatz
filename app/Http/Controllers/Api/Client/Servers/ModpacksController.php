<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Modpacks\BrowseModpacksRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Modpacks\InstallModpackRequest;
use Pterodactyl\Jobs\Server\InstallModpackJob;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerOperation;
use Pterodactyl\Services\Modpacks\ModpackPlanner;
use Pterodactyl\Services\Modpacks\ModrinthModpacks;
use Pterodactyl\Services\ServerOperations\ServerOperationService;

/**
 * One-click Modrinth modpack installs (fresh install: wipe, switch loader egg,
 * download the pack).
 */
class ModpacksController extends ClientApiController
{
    public function __construct(
        private ModrinthModpacks $modrinth,
        private ModpackPlanner $planner,
        private ServerOperationService $operations,
    ) {
        parent::__construct();
    }

    public function search(BrowseModpacksRequest $request): JsonResponse
    {
        return new JsonResponse($this->modrinth->search(
            (string) $request->input('query', ''),
            (int) $request->input('offset', 0),
            $request->input('loader'),
        ));
    }

    public function versions(BrowseModpacksRequest $request): JsonResponse
    {
        $request->validate(['project_id' => 'required']);

        return new JsonResponse(['versions' => $this->modrinth->versions((string) $request->input('project_id'))]);
    }

    public function install(InstallModpackRequest $request, Server $server): JsonResponse
    {
        // Plan (download + validate the index) before touching anything on the server.
        $plan = $this->planner->plan($request->input('version_id'));

        $operation = $this->operations->createOperation($server, $request->user(), 'modpack_install', [
            'project_id' => $plan['project_id'],
            'version_id' => $plan['version_id'],
            'title' => $plan['title'],
            'version_name' => $plan['version_name'],
            'loader' => $plan['loader'],
            'minecraft' => $plan['minecraft'],
        ], 'Queued modpack install...');

        InstallModpackJob::dispatch($server, $request->user(), $plan, $operation->operation_id);

        Activity::event('server:modpack.install-queued')
            ->property(['modpack' => $plan['title'] . ' ' . $plan['version_name'], 'files' => count($plan['files'])])
            ->log();

        return new JsonResponse([
            'operation_id' => $operation->operation_id,
            'title' => $plan['title'],
            'version_name' => $plan['version_name'],
            'loader' => $plan['loader'],
            'loader_version' => $plan['loader_version'],
            'minecraft' => $plan['minecraft'],
            'files' => count($plan['files']),
        ], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * Latest modpack install for this server (so the page can resume progress).
     */
    public function status(BrowseModpacksRequest $request, Server $server): JsonResponse
    {
        $operation = ServerOperation::forServer($server)->ofType('modpack_install')->orderByDesc('created_at')->first();
        if ($operation === null) {
            return new JsonResponse(['operation' => null]);
        }
        if ($operation->isActive() && $operation->hasTimedOut(60)) {
            $operation->markAsFailed('Modpack install timed out.');
        }

        return new JsonResponse(['operation' => $this->operations->formatOperationResponse($operation)]);
    }
}
