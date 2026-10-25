<?php

namespace Pterodactyl\Listeners\Discord;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\ActivityLog;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Events\ActivityLogged;
use Pterodactyl\Services\Discord\DiscordNotifier;

class DiscordActivityListener
{
    public function handle(ActivityLogged $event): void
    {
        if (!DiscordNotifier::wants($event->model->event) || !DiscordNotifier::enabled()) {
            return;
        }

        // Subjects are attached after the log row is created, in the same
        // transaction; wait for the commit so they can be read.
        $id = $event->model->id;
        DB::afterCommit(function () use ($id) {
            try {
                $log = ActivityLog::query()->find($id);
                if ($log !== null) {
                    DiscordNotifier::activity($log);
                }
            } catch (\Throwable $exception) {
                Log::warning('Discord activity notification failed', ['activity' => $id, 'error' => $exception->getMessage()]);
            }
        });
    }
}
