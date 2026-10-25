<?php

namespace Pterodactyl\Jobs\Discord;

use Pterodactyl\Jobs\Job;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Pterodactyl\Services\Discord\DiscordClient;
use Pterodactyl\Services\Discord\DiscordException;

/**
 * Sends one message to a channel or a user's DMs. Retries on rate limits and
 * network trouble; drops it for errors that will never succeed.
 */
class SendDiscordMessage extends Job implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;

    public int $tries = 6;

    /** "channel:<id>" or "user:<id>" */
    public function __construct(public string $target, public array $payload)
    {
        $this->queue = 'low';
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(): void
    {
        if (!DiscordClient::configured()) {
            return;
        }

        try {
            (new DiscordClient())->send($this->target, $this->payload);
        } catch (DiscordException $exception) {
            if ($exception->retryAfter !== null) {
                $this->release($exception->retryAfter);

                return;
            }
            if ($exception->permanent) {
                // DMs to users who left the server or closed DMs are expected; not worth an error.
                Log::info('Discord message dropped', ['target' => $this->target, 'reason' => $exception->getMessage()]);

                return;
            }
            throw $exception;
        }
    }
}
