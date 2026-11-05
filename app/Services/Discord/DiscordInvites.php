<?php

namespace Pterodactyl\Services\Discord;

use Pterodactyl\Models\User;
use Illuminate\Support\Facades\Password;
use Pterodactyl\Exceptions\DisplayException;

/**
 * Account invites by Discord DM instead of email: find the person in the
 * panel's Discord server, link their Discord to the new account, and DM them
 * a one-time link to set their password. Turned on in Admin -> Settings ->
 * Discord ("Send account invites by").
 */
class DiscordInvites
{
    public static function enabled(): bool
    {
        return config('hydrodactyl.discord.invites') === 'discord' && DiscordClient::configured();
    }

    /**
     * Find someone in the Discord server by username or user ID.
     *
     * @return array{id: string, username: string}
     *
     * @throws DisplayException
     */
    public static function findMember(string $input): array
    {
        $input = trim(ltrim(trim($input), '@'));
        if ($input === '' || mb_strlen($input) > 64 || preg_match('/[\p{C}]/u', $input)) {
            throw new DisplayException('Enter their Discord username (or user ID).');
        }

        try {
            $client = new DiscordClient();
            if (preg_match('/\A\d{15,22}\z/', $input)) {
                $member = $client->member($input);
                if ($member === null) {
                    throw new DisplayException("That Discord user isn't in the Discord server. Invite them to it first.");
                }

                return self::shape($member);
            }

            $members = $client->searchMembers($input);
        } catch (DiscordException $exception) {
            throw new DisplayException(match (true) {
                in_array($exception->getCode(), [50001, 50013], true), str_contains($exception->getMessage(), '403') => "The bot can't look up server members. In the Discord Developer Portal, turn on Server Members Intent for the panel bot.",
                str_contains($exception->getMessage(), '401') => 'The panel bot token is wrong. Check Admin -> Settings -> Discord.',
                default => 'Discord lookup failed: ' . $exception->getMessage(),
            });
        }

        $needle = mb_strtolower($input);
        // Usernames are unique, so an exact username match wins.
        foreach ($members as $member) {
            if (mb_strtolower((string) ($member['user']['username'] ?? '')) === $needle && !($member['user']['bot'] ?? false)) {
                return self::shape($member);
            }
        }
        // Otherwise accept a display name or nickname, but only if it's unambiguous.
        $byName = array_values(array_filter($members, fn (array $member) => !($member['user']['bot'] ?? false) && in_array($needle, [
            mb_strtolower((string) ($member['nick'] ?? '')),
            mb_strtolower((string) ($member['user']['global_name'] ?? '')),
        ], true)));
        if (count($byName) === 1) {
            return self::shape($byName[0]);
        }

        throw new DisplayException(count($byName) > 1
            ? "More than one person in the Discord server goes by \"{$input}\". Use their exact username instead."
            : "Couldn't find \"{$input}\" in the Discord server. They need to join it first, and use their username, not their display name.");
    }

    /**
     * DM a one-time link to set their password and sign in.
     *
     * @param array{id: string, username: string} $member
     *
     * @throws DisplayException
     */
    public static function sendSetupLink(User $user, array $member, string $reason, ?string $title = null): void
    {
        $token = Password::broker()->createToken($user);
        $url = url('/auth/password/reset/' . $token . '?email=' . urlencode($user->email));
        $brand = (string) config('hydrodactyl.discord.brand', 'Da Catz Hosting');
        $hours = max(1, intdiv((int) config('auth.passwords.users.expire', 60), 60));

        $payload = [
            'embeds' => [[
                'title' => $title ?? "You're invited to {$brand}",
                'color' => DiscordNotifier::GREEN,
                'description' => DiscordNotifier::escape($reason, 200) . "\n\n"
                    . 'Your panel username is **' . DiscordNotifier::escape($user->username) . '**. '
                    . 'Use the button to set a ' . ($title === null ? '' : 'new ') . 'password, then sign in with your username or email.',
                'footer' => ['text' => "{$brand} · link works once, for {$hours} " . ($hours === 1 ? 'hour' : 'hours')],
                'timestamp' => now()->toIso8601String(),
            ]],
        ];
        if (str_starts_with($url, 'https://')) {
            $payload['components'] = [['type' => 1, 'components' => [['type' => 2, 'style' => 5, 'label' => 'Set your password', 'url' => $url]]]];
        } else {
            $payload['embeds'][0]['description'] .= "\n\n" . $url;
        }

        try {
            (new DiscordClient())->send('user:' . $member['id'], $payload);
        } catch (DiscordException $exception) {
            throw new DisplayException(in_array($exception->getCode(), [50007, 50278], true)
                ? "The account was created, but {$member['username']} has DMs from server members turned off. Ask them to allow DMs, then use \"Resend invite\" on their user page."
                : 'The account was created, but the Discord DM failed: ' . $exception->getMessage());
        }
    }

    /** Give the account the Discord identity we just verified, unless someone else has it. */
    public static function link(User $user, array $member): void
    {
        if (User::query()->where('discord_id', $member['id'])->where('id', '!=', $user->id)->exists()) {
            return;
        }
        $user->forceFill(['discord_id' => $member['id'], 'discord_username' => $member['username']])->saveQuietly();
    }

    /** A panel-safe username based on their Discord name. */
    public static function usernameFor(array $member, string $email): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9_.-]+/i', '', $member['username']) ?? '');
        $base = trim($base, '._-');
        if (strlen($base) < 3) {
            $base = strtolower(trim(preg_replace('/[^\w.-]+/', '', strtok($email, '@') ?: '') ?? '', '._-'));
        }
        $base = substr($base !== '' ? $base : 'user', 0, 40);
        $username = $base;
        for ($i = 0; User::query()->where('username', $username)->exists(); $i++) {
            $username = $base . strtolower(\Illuminate\Support\Str::random(3));
            if ($i > 5) {
                break;
            }
        }

        return $username;
    }

    private static function shape(array $member): array
    {
        $user = (array) ($member['user'] ?? []);
        $id = (string) ($user['id'] ?? '');
        if (!preg_match('/\A\d{15,22}\z/', $id)) {
            throw new DisplayException('Discord returned an unexpected member.');
        }
        if ($user['bot'] ?? false) {
            throw new DisplayException("That's a bot account.");
        }

        return ['id' => $id, 'username' => mb_substr((string) ($user['username'] ?? $id), 0, 64)];
    }
}
