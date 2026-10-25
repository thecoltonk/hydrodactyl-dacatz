<?php

namespace Pterodactyl\Observers;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Services\Discord\DiscordNotifier;

/**
 * Announces servers being created and deleted, whoever did it (admin panel,
 * self-service plans, or the application API).
 */
class DiscordServerObserver
{
    public function created(Server $server): void
    {
        $this->safely(fn () => DiscordNotifier::serverCreated($server, $this->actor()));
    }

    public function deleted(Server $server): void
    {
        $this->safely(fn () => DiscordNotifier::serverDeleted($server, $this->actor()));
    }

    private function actor(): ?User
    {
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            return null;
        }
        $user = request()->user();

        return $user instanceof User ? $user : null;
    }

    private function safely(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            Log::warning('Discord server notification failed', ['error' => $exception->getMessage()]);
        }
    }
}
