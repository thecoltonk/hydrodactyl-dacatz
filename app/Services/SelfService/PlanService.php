<?php

namespace Pterodactyl\Services\SelfService;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserPlan;
use Pterodactyl\Services\Servers\ServerCreationService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Self-service servers: users with a plan create, resize and delete their own
 * servers, and everything they own counts against the plan's totals.
 */
class PlanService
{
    /** Smallest server a user can make. */
    public const MIN_MEMORY = 512;

    public const MIN_CPU = 50;

    public const MIN_DISK = 1024;

    public function __construct(private ServerCreationService $creation)
    {
    }

    public function plan(User $user): ?UserPlan
    {
        return UserPlan::query()->where('user_id', $user->id)->first();
    }

    /**
     * What the user's servers use right now, optionally ignoring one server
     * (the one being resized).
     *
     * @return array{memory: int, cpu: int, disk: int, servers: int}
     */
    public function usage(User $user, ?int $exceptServerId = null): array
    {
        $query = Server::query()->where('owner_id', $user->id);
        if ($exceptServerId !== null) {
            $query->where('id', '!=', $exceptServerId);
        }
        $row = $query->selectRaw('COUNT(*) as servers, COALESCE(SUM(memory),0) as memory, COALESCE(SUM(cpu),0) as cpu, COALESCE(SUM(disk),0) as disk')->first();

        return [
            'memory' => (int) $row->memory,
            'cpu' => (int) $row->cpu,
            'disk' => (int) $row->disk,
            'servers' => (int) $row->servers,
        ];
    }

    /**
     * Throw a friendly 422 unless the requested size fits the plan.
     */
    public function assertFits(UserPlan $plan, User $user, int $memory, int $cpu, int $disk, ?Server $existing = null): void
    {
        if ($memory < self::MIN_MEMORY || $cpu < self::MIN_CPU || $disk < self::MIN_DISK) {
            throw new HttpException(422, 'Servers need at least 0.5 GB RAM, 0.5 CPU cores and 1 GB of storage.');
        }
        $used = $this->usage($user, $existing?->id);
        if ($existing === null && $plan->servers > 0 && $used['servers'] >= $plan->servers) {
            throw new HttpException(422, sprintf('Your plan allows %d servers. Delete one to make room.', $plan->servers));
        }
        foreach ([
            'memory' => ['RAM', $memory, fn (int $v) => self::gb($v) . ' GB'],
            'cpu' => ['CPU', $cpu, fn (int $v) => rtrim(rtrim(number_format($v / 100, 2), '0'), '.') . ($v === 100 ? ' core' : ' cores')],
            'disk' => ['storage', $disk, fn (int $v) => self::gb($v) . ' GB'],
        ] as $key => [$label, $wanted, $format]) {
            $left = max(0, $plan->{$key} - $used[$key]);
            if ($wanted > $left) {
                throw new HttpException(422, sprintf('Not enough %s left in your plan: %s available, %s requested.', $label, $format($left), $format($wanted)));
            }
        }
    }

    /**
     * Eggs a user can pick from, grouped by nest.
     */
    public function eggs(): array
    {
        return Egg::query()->with('nest:id,name')->orderBy('nest_id')->orderBy('name')->get()
            ->map(fn (Egg $egg) => [
                'id' => $egg->id,
                'name' => $egg->name,
                'description' => mb_substr((string) $egg->description, 0, 300),
                'nest' => $egg->nest?->name ?? 'Other',
            ])->values()->all();
    }

    /**
     * Create a server for the user inside their plan, on whichever node has
     * room and a free port.
     */
    /**
     * @param array{egg_id: int, docker_image: string, environment: array<string, string>}|null $template
     *        Use this egg/image/environment instead of the egg's defaults (modpacks).
     */
    public function create(User $user, string $name, int $eggId, int $memory, int $cpu, int $disk, ?array $template = null): Server
    {
        if ($template !== null) {
            $eggId = (int) $template['egg_id'];
        }
        $plan = $this->plan($user);
        if ($plan === null) {
            throw new HttpException(403, 'Your account does not have a server plan.');
        }

        $lock = Cache::lock('self-service:user:' . $user->id, 60);
        if (!$lock->block(15)) {
            throw new HttpException(429, 'Another change to your servers is in progress. Try again in a moment.');
        }
        try {
            $this->assertFits($plan, $user, $memory, $cpu, $disk);

            $egg = Egg::query()->with('variables')->find($eggId);
            if ($egg === null) {
                throw new HttpException(422, 'That game is not available.');
            }
            $images = array_values((array) $egg->docker_images);
            if ($template !== null) {
                array_unshift($images, $template['docker_image']);
            }
            if ($images === []) {
                throw new HttpException(422, 'That game has no Docker image configured. Ask an admin.');
            }
            $allocation = $this->allocation($memory, $disk);

            $environment = [];
            foreach ($egg->variables as $variable) {
                $environment[$variable->env_variable] = (string) ($variable->default_value ?? '');
            }
            if ($template !== null) {
                $environment = array_replace($environment, array_map('strval', $template['environment']));
            }

            return $this->creation->handle([
                'name' => $name,
                'description' => '',
                'owner_id' => $user->id,
                'egg_id' => $egg->id,
                'nest_id' => $egg->nest_id,
                'node_id' => $allocation->node_id,
                'allocation_id' => $allocation->id,
                'memory' => $memory,
                'swap' => 0,
                'disk' => $disk,
                'io' => 500,
                'cpu' => $cpu,
                'threads' => null,
                'oom_disabled' => true,
                'startup' => $egg->startup,
                'image' => $images[0],
                'environment' => $environment,
                'database_limit' => (int) config('hydrodactyl.self_service.database_limit', 2),
                'allocation_limit' => (int) config('hydrodactyl.self_service.allocation_limit', 3),
                'backup_limit' => (int) config('hydrodactyl.self_service.backup_limit', 3),
                'backup_storage_limit' => null,
                'start_on_completion' => false,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * First free port on the node with the most RAM headroom. If nothing fits,
     * say exactly why so an admin knows what to change.
     */
    private function allocation(int $memory, int $disk): Allocation
    {
        $reasons = [];
        $candidates = [];
        foreach (Node::query()->get() as $node) {
            if ($node->maintenance_mode) {
                $reasons[] = $node->name . ' is in maintenance mode';
                continue;
            }
            $memoryLimit = (int) ($node->memory * (1 + $node->memory_overallocate / 100));
            $diskLimit = (int) ($node->disk * (1 + $node->disk_overallocate / 100));
            $counted = $node->servers()->where('exclude_from_resource_calculation', false);
            $memoryFree = $memoryLimit - (int) (clone $counted)->sum('memory');
            $diskFree = $diskLimit - (int) (clone $counted)->sum('disk');
            if ($memory > $memoryFree) {
                $reasons[] = sprintf('%s has %s GB RAM unallocated', $node->name, self::gb(max(0, $memoryFree)));
                continue;
            }
            if ($disk > $diskFree) {
                $reasons[] = sprintf('%s has %s GB storage unallocated', $node->name, self::gb(max(0, $diskFree)));
                continue;
            }
            $allocation = Allocation::query()->where('node_id', $node->id)->whereNull('server_id')->orderBy('port')->first();
            if ($allocation === null) {
                $reasons[] = $node->name . ' has no free ports (add more under Admin > Nodes > Allocation)';
                continue;
            }
            $candidates[] = [$memoryFree, $allocation];
        }
        if ($candidates !== []) {
            usort($candidates, fn ($a, $b) => $b[0] <=> $a[0]);

            return $candidates[0][1];
        }

        throw new HttpException(422, 'No node has room for that server: ' . ($reasons === [] ? 'no nodes exist' : implode('; ', $reasons)) . '.');
    }

    public static function gb(int $mib): string
    {
        return rtrim(rtrim(number_format($mib / 1024, 2), '0'), '.');
    }
}
