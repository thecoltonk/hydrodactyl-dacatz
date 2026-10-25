<?php

namespace Pterodactyl\Services\Discord;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Discord REST client for the panel's bot. Everything goes over
 * HTTPS with the bot token; there is no gateway connection.
 */
class DiscordClient
{
    public const API = 'https://discord.com/api/v10';

    /** Discord error codes that mean "this will never work, drop it". */
    private const PERMANENT = [
        10003, // unknown channel
        10013, // unknown user
        50001, // missing access
        50007, // cannot send messages to this user (DMs closed / no shared server)
        50013, // missing permissions
        50278, // cannot DM this user
    ];

    public function __construct(private ?string $token = null)
    {
        $this->token ??= (string) config('hydrodactyl.discord.bot_token');
    }

    public static function configured(): bool
    {
        return (bool) config('hydrodactyl.discord.enabled') && trim((string) config('hydrodactyl.discord.bot_token')) !== '';
    }

    /**
     * Post a message. $target is "channel:<id>" or "user:<id>".
     *
     * @throws DiscordException
     */
    public function send(string $target, array $payload): void
    {
        [$kind, $id] = explode(':', $target, 2) + [null, null];
        if (!is_string($id) || !preg_match('/\A\d{15,22}\z/', $id)) {
            throw new DiscordException('Invalid Discord target.', permanent: true);
        }

        $channel = $kind === 'user' ? $this->dmChannel($id) : $id;
        // Never ping anyone, whatever text ends up in the message.
        $payload['allowed_mentions'] = ['parse' => []];

        $this->check($this->http()->post(self::API . "/channels/{$channel}/messages", $payload));
    }

    /**
     * Bot identity, used by the admin "test" button.
     *
     * @throws DiscordException
     */
    public function me(): array
    {
        $response = $this->http()->get(self::API . '/users/@me');
        $this->check($response);

        return (array) $response->json();
    }

    /**
     * @throws DiscordException
     */
    private function dmChannel(string $userId): string
    {
        $key = 'discord:dm:' . $userId;
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }

        $response = $this->http()->post(self::API . '/users/@me/channels', ['recipient_id' => $userId]);
        $this->check($response);
        $channel = (string) $response->json('id');
        Cache::put($key, $channel, now()->addDay());

        return $channel;
    }

    private function http()
    {
        return Http::withHeaders([
            'Authorization' => 'Bot ' . trim((string) $this->token),
            'User-Agent' => 'DiscordBot (https://github.com/thecoltonk/hydrodactyl-dacatz, 1.0)',
        ])->acceptJson()->asJson()->timeout(10)->connectTimeout(5);
    }

    /**
     * @throws DiscordException
     */
    private function check(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        if ($status === 429) {
            $retry = (float) ($response->json('retry_after') ?? $response->header('Retry-After') ?: 5);
            throw new DiscordException('Rate limited by Discord.', retryAfter: max(1, (int) ceil($retry)));
        }

        $code = (int) $response->json('code', 0);
        $message = (string) $response->json('message', 'HTTP ' . $status);
        throw new DiscordException(
            "Discord said: {$message} (HTTP {$status}" . ($code ? ", code {$code}" : '') . ')',
            permanent: $status === 401 || in_array($code, self::PERMANENT, true) || ($status >= 400 && $status < 500),
            code: $code,
        );
    }
}
