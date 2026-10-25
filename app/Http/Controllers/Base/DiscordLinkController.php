<?php

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Pterodactyl\Models\User;
use Pterodactyl\Facades\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Discord\DiscordClient;
use Pterodactyl\Services\Discord\DiscordNotifier;

/**
 * Links a panel account to a Discord account with OAuth2 ("identify" scope
 * only). The Discord access token is used once to read the user id and then
 * thrown away.
 */
class DiscordLinkController extends Controller
{
    private const SESSION = 'discord_oauth';

    public static function available(): bool
    {
        return DiscordClient::configured()
            && trim((string) config('hydrodactyl.discord.application_id')) !== ''
            && trim((string) config('hydrodactyl.discord.client_secret')) !== '';
    }

    public function redirect(Request $request): RedirectResponse
    {
        if ($request->user() === null) {
            return redirect('/auth/login');
        }
        if (!self::available()) {
            return $this->back('disabled');
        }

        $state = Str::random(40);
        $request->session()->put(self::SESSION, ['state' => $state, 'user' => $request->user()->id, 'at' => time()]);

        return redirect()->away('https://discord.com/oauth2/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => config('hydrodactyl.discord.application_id'),
            'scope' => 'identify',
            'state' => $state,
            'redirect_uri' => route('account.discord.callback'),
            'prompt' => 'none',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->user() === null) {
            return redirect('/auth/login');
        }
        $saved = $request->session()->pull(self::SESSION);
        $state = (string) $request->query('state', '');
        if (
            !is_array($saved)
            || !is_string($saved['state'] ?? null)
            || $state === ''
            || !hash_equals($saved['state'], $state)
            || ($saved['user'] ?? null) !== $request->user()->id
            || time() - (int) ($saved['at'] ?? 0) > 600
        ) {
            return $this->back('expired');
        }
        if ($request->filled('error')) {
            return $this->back('cancelled');
        }
        $code = (string) $request->query('code', '');
        if (!self::available() || $code === '' || strlen($code) > 200) {
            return $this->back('failed');
        }

        try {
            $token = Http::asForm()->acceptJson()->timeout(10)->post(DiscordClient::API . '/oauth2/token', [
                'client_id' => config('hydrodactyl.discord.application_id'),
                'client_secret' => config('hydrodactyl.discord.client_secret'),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => route('account.discord.callback'),
            ]);
            $access = $token->json('access_token');
            if (!$token->successful() || !is_string($access)) {
                Log::info('Discord OAuth token exchange failed', ['status' => $token->status(), 'error' => $token->json('error')]);

                return $this->back('failed');
            }

            $me = Http::withToken($access)->acceptJson()->timeout(10)->get(DiscordClient::API . '/users/@me');
            $id = (string) $me->json('id');
            if (!$me->successful() || !preg_match('/\A\d{15,22}\z/', $id)) {
                return $this->back('failed');
            }
            $username = mb_substr((string) ($me->json('username') ?? ''), 0, 64);
        } catch (\Throwable $exception) {
            Log::warning('Discord OAuth failed', ['error' => $exception->getMessage()]);

            return $this->back('failed');
        }

        /** @var User $user */
        $user = $request->user();
        if (User::query()->where('discord_id', $id)->where('id', '!=', $user->id)->exists()) {
            return $this->back('taken');
        }

        $user->forceFill(['discord_id' => $id, 'discord_username' => $username])->saveQuietly();
        Activity::event('user:discord.linked')->subject($user)->property('discord', $username)->log();

        // Send the confirmation now so we can tell the user whether DMs work.
        try {
            (new DiscordClient())->send('user:' . $id, DiscordNotifier::linkedMessage($user));
        } catch (\Throwable) {
            return $this->back('nodm');
        }

        return $this->back('linked');
    }

    private function back(string $status): RedirectResponse
    {
        return redirect('/account?discord=' . $status);
    }
}
