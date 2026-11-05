<?php

namespace Pterodactyl\Jobs\Server;

use Pterodactyl\Jobs\Job;
use Pterodactyl\Models\Server;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Pterodactyl\Services\MinecraftPlayers\ItemIcons;

/** Reads every jar in /mods once to learn which mod owns which item namespace. */
class IndexItemIconsJob extends Job implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;

    public int $tries = 1;

    public int $timeout = 85;

    public function __construct(public int $serverId)
    {
        $this->queue = 'low';
    }

    public function handle(ItemIcons $icons): void
    {
        $server = Server::query()->find($this->serverId);
        // Each run handles about a minute of jars, then queues the next slice.
        if ($server !== null && !$icons->build($server)) {
            self::dispatch($this->serverId);
        }
    }
}
