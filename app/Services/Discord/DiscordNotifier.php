<?php

namespace Pterodactyl\Services\Discord;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\ActivityLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Jobs\Discord\SendDiscordMessage;

/**
 * Turns panel events into Discord embeds: one post in the staff feed channel
 * and, for users who linked their Discord account, a DM about their own
 * servers and account.
 *
 * The feed channel is visible to people without panel access, so messages
 * never include IP addresses, emails, keys or file contents.
 */
class DiscordNotifier
{
    public const GREEN = 0x43C760;
    public const RED = 0xC74343;
    public const AMBER = 0xC7AA43;
    public const BLUE = 0x4381C7;
    public const GRAY = 0x71717A;

    private const SECURITY_NOTE = "If this wasn't you, change your password and tell an admin.";

    /**
     * Activity events we post. dm: who gets a DM.
     *   owner      server owner, always
     *   owner-other server owner, unless they did it themselves
     *   account    the account the event is about
     * dedupe: seconds to suppress repeats for the same account/server.
     */
    private const EVENTS = [
        // Lifecycle
        'server:reinstall' => ['title' => 'Server reinstalled', 'color' => self::BLUE, 'dm' => 'owner-other'],
        'server:settings.quick-build' => ['title' => 'Resources changed', 'color' => self::BLUE, 'dm' => 'owner-other'],
        'server:modpack.installed' => ['title' => 'Modpack installed', 'color' => self::GREEN, 'dm' => 'owner'],
        'server:software.changed' => ['title' => 'Server software changed', 'color' => self::GREEN, 'dm' => 'owner'],
        // Failures
        'server:software.change-failed' => ['title' => 'Software change failed', 'color' => self::RED, 'dm' => 'owner'],
        'server:backup.fail' => ['title' => 'Backup failed', 'color' => self::RED, 'dm' => 'owner', 'dedupe' => 120, 'key' => 'backup_create'],
        'server:backup.restore-failed' => ['title' => 'Backup restore failed', 'color' => self::RED, 'dm' => 'owner', 'dedupe' => 120, 'key' => 'backup_restore'],
        'server.backup.restore-failed' => ['title' => 'Backup restore failed', 'color' => self::RED, 'dm' => 'owner', 'dedupe' => 120, 'key' => 'backup_restore'],
        // Security (admin logins are deliberately not posted)
        'auth:fail' => ['title' => 'Failed login', 'color' => self::AMBER, 'dm' => 'account', 'dedupe' => 1800, 'security' => true],
        'auth:sftp.fail' => ['title' => 'Failed SFTP login', 'color' => self::AMBER, 'dm' => 'account', 'dedupe' => 1800, 'security' => true],
        'auth:ip-blocked' => ['title' => 'API key used from a blocked IP', 'color' => self::AMBER, 'dm' => 'account', 'dedupe' => 1800, 'security' => true],
        'server:sftp.denied' => ['title' => 'SFTP access denied', 'color' => self::AMBER, 'dm' => null, 'dedupe' => 1800],
        'user:account.password-changed' => ['title' => 'Password changed', 'color' => self::AMBER, 'dm' => 'account', 'security' => true],
        'user:account.email-changed' => ['title' => 'Email changed', 'color' => self::AMBER, 'dm' => 'account', 'security' => true],
        'user:two-factor.create' => ['title' => 'Two-factor turned on', 'color' => self::GREEN, 'dm' => 'account'],
        'user:two-factor.delete' => ['title' => 'Two-factor turned off', 'color' => self::AMBER, 'dm' => 'account', 'security' => true],
        'user:api-key.create' => ['title' => 'API key created', 'color' => self::AMBER, 'dm' => 'account', 'security' => true],
        'user:ssh-key.create' => ['title' => 'SSH key added', 'color' => self::AMBER, 'dm' => 'account', 'security' => true],
        'user:discord.linked' => ['title' => 'Discord account linked', 'color' => self::BLUE, 'dm' => null],
        'user:discord.unlinked' => ['title' => 'Discord account unlinked', 'color' => self::GRAY, 'dm' => null],
    ];

    public static function enabled(): bool
    {
        return DiscordClient::configured();
    }

    public static function wants(string $event): bool
    {
        return isset(self::EVENTS[$event]);
    }

    public static function activity(ActivityLog $log): void
    {
        $rule = self::EVENTS[$log->event] ?? null;
        if ($rule === null || !self::enabled()) {
            return;
        }

        $log->loadMissing(['actor', 'subjects.subject']);
        $server = null;
        $account = null;
        foreach ($log->subjects as $subject) {
            $model = $subject->subject;
            if ($model instanceof Server && $server === null) {
                $server = $model;
            } elseif ($model instanceof User && $account === null) {
                $account = $model;
            }
        }
        $actor = $log->actor instanceof User ? $log->actor : null;
        $account ??= $server === null ? $actor : null;
        $properties = $log->properties?->toArray() ?? [];

        $title = $rule['title'];
        $color = $rule['color'];
        if ($log->event === 'auth:fail') {
            // Only real accounts: typed-in usernames could be anything.
            if ($account === null) {
                return;
            }
            // The 2FA checkpoint reports failures with no username: the password was right.
            if (array_filter($properties, fn ($value) => $value !== null && $value !== '') === []) {
                $title = 'Wrong two-factor code (password was correct)';
                $color = self::RED;
            }
        }

        $dedupe = $rule['dedupe'] ?? null;
        if ($dedupe !== null) {
            $key = ($rule['key'] ?? $log->event) . ':' . ($server?->id ? 's' . $server->id : 'u' . ($account?->id ?? 0));
            if (!self::once($key, $dedupe)) {
                return;
            }
        }

        $fields = [];
        if ($account !== null) {
            $fields[] = self::field('Account', $account->username);
        }
        if ($server !== null) {
            $fields[] = self::field('Owner', $server->user?->username ?? 'unknown');
        }
        if ($actor !== null && $actor->id !== $account?->id && $actor->id !== $server?->owner_id) {
            $fields[] = self::field('By', $actor->username);
        }
        $fields = array_merge($fields, self::extraFields($log->event, $properties));

        $embed = [
            'title' => $title,
            'color' => $color,
            'fields' => $fields,
        ];
        if ($server !== null) {
            $embed['description'] = self::serverLink($server);
        }
        if ($dedupe !== null && ($rule['security'] ?? false)) {
            $embed['footer'] = ['text' => self::brand() . ' · repeats hidden for ' . intdiv($dedupe, 60) . ' min'];
        }

        $recipient = match ($rule['dm']) {
            'owner' => $server?->user,
            'owner-other' => $server !== null && $actor?->id !== $server->owner_id ? $server->user : null,
            'account' => $account,
            default => null,
        };

        self::send($embed, $recipient, ($rule['security'] ?? false) ? self::SECURITY_NOTE : null);
    }

    public static function serverCreated(Server $server, ?User $actor): void
    {
        if (!self::enabled()) {
            return;
        }
        $owner = $server->user;
        $fields = [self::field('Owner', $owner?->username ?? 'unknown')];
        if ($actor !== null && $actor->id !== $server->owner_id) {
            $fields[] = self::field('By', $actor->username);
        }
        $fields[] = self::field('Resources', self::resources($server), true);
        if ($server->egg !== null) {
            $fields[] = self::field('Type', $server->egg->name);
        }

        self::send([
            'title' => 'Server created',
            'color' => self::GREEN,
            'description' => self::serverLink($server),
            'fields' => $fields,
        ], $actor?->id !== $server->owner_id ? $owner : null);
    }

    public static function serverDeleted(Server $server, ?User $actor): void
    {
        if (!self::enabled()) {
            return;
        }
        $owner = $server->user;
        $fields = [self::field('Owner', $owner?->username ?? 'unknown')];
        if ($actor !== null && $actor->id !== $server->owner_id) {
            $fields[] = self::field('By', $actor->username);
        }

        self::send([
            'title' => 'Server deleted',
            'color' => self::GRAY,
            'description' => '**' . self::escape($server->name) . '**',
            'fields' => $fields,
        ], $actor?->id !== $server->owner_id ? $owner : null);
    }

    public static function installFinished(Server $server, bool $successful, bool $reinstall): void
    {
        if (!self::enabled()) {
            return;
        }
        if ($successful) {
            // Success is only interesting to the owner, and only the first time.
            if (!$reinstall) {
                self::send([
                    'title' => 'Server is ready',
                    'color' => self::GREEN,
                    'description' => self::serverLink($server) . "\nIt finished installing. Start it from the panel.",
                ], $server->user, null, false);
            }

            return;
        }

        self::send([
            'title' => $reinstall ? 'Reinstall failed' : 'Install failed',
            'color' => self::RED,
            'description' => self::serverLink($server) . "\nCheck the server console for the install log.",
            'fields' => [self::field('Owner', $server->user?->username ?? 'unknown')],
        ], $server->user);
    }

    /** A daemon-side job (backups on Elytra) ended in failure. */
    public static function jobFailed(Server $server, string $jobType): void
    {
        if (!self::enabled() || !self::once($jobType . ':s' . $server->id, 120)) {
            return;
        }

        $title = match ($jobType) {
            'backup_create' => 'Backup failed',
            'backup_restore' => 'Backup restore failed',
            'backup_delete' => 'Backup delete failed',
            default => ucfirst(str_replace('_', ' ', $jobType)) . ' failed',
        };
        self::send([
            'title' => $title,
            'color' => self::RED,
            'description' => self::serverLink($server),
            'fields' => [self::field('Owner', $server->user?->username ?? 'unknown')],
        ], $server->user);
    }

    public static function modpackFailed(Server $server, string $modpack): void
    {
        if (!self::enabled()) {
            return;
        }
        self::send([
            'title' => 'Modpack install failed',
            'color' => self::RED,
            'description' => self::serverLink($server) . "\nThe panel's Operations list has the error.",
            'fields' => [
                self::field('Owner', $server->user?->username ?? 'unknown'),
                self::field('Modpack', $modpack),
            ],
        ], $server->user);
    }

    /** Linked-account confirmation, sent right away so the user knows DMs work. */
    public static function linkedMessage(User $user): array
    {
        return ['embeds' => [self::finish([
            'title' => 'Discord linked',
            'color' => self::GREEN,
            'description' => 'This Discord account is now linked to **' . self::escape($user->username) . "** on the panel.\n"
                . "You'll get DMs when your servers finish installing, fail, or change, and when something security-related happens on your account.\n"
                . 'Unlink any time from Account Settings.',
        ])]];
    }

    /**
     * Queue the feed post and the DM. Runs after the surrounding database
     * transaction commits, so rolled-back actions never get announced.
     */
    public static function send(array $embed, ?User $dm = null, ?string $dmNote = null, bool $feed = true): void
    {
        $embed = self::finish($embed);
        $channel = trim((string) config('hydrodactyl.discord.channel_id'));
        $dmTarget = $dm?->discord_id ? 'user:' . $dm->discord_id : null;

        DB::afterCommit(function () use ($embed, $channel, $feed, $dmTarget, $dmNote) {
            try {
                // Hard cap so a flood (scripted login attempts, mass deletes) can't spam the channel.
                if ($feed && $channel !== '' && self::budget('feed', 30, 300)) {
                    SendDiscordMessage::dispatch('channel:' . $channel, ['embeds' => [$embed]]);
                }
                if ($dmTarget !== null && self::budget($dmTarget, 10, 300)) {
                    $personal = $embed;
                    if ($dmNote !== null) {
                        $personal['description'] = trim(($personal['description'] ?? '') . "\n\n" . $dmNote);
                    }
                    SendDiscordMessage::dispatch($dmTarget, ['embeds' => [$personal]]);
                }
            } catch (\Throwable $exception) {
                // Discord must never break the action that triggered it.
                Log::warning('Could not queue Discord message', ['error' => $exception->getMessage()]);
            }
        });
    }

    public static function escape(string $text, int $max = 100): string
    {
        $text = preg_replace('/[\p{C}]+/u', ' ', $text) ?? '';
        $text = mb_strimwidth(trim($text), 0, $max, '…');

        return preg_replace('/([\\\\*_~`|>#\[\]()<@])/', '\\\\$1', $text) ?? '';
    }

    private static function finish(array $embed): array
    {
        $embed['footer'] ??= ['text' => self::brand()];
        $embed['timestamp'] = now()->toIso8601String();
        if (($embed['fields'] ?? []) === []) {
            unset($embed['fields']);
        }

        return $embed;
    }

    private static function extraFields(string $event, array $properties): array
    {
        if ($event === 'server:settings.quick-build') {
            $old = (array) ($properties['old'] ?? []);
            $new = (array) ($properties['new'] ?? []);
            $parts = [];
            foreach (['memory' => 'RAM', 'cpu' => 'CPU', 'disk' => 'Storage'] as $key => $label) {
                if (isset($new[$key]) && ($old[$key] ?? null) !== $new[$key]) {
                    $parts[] = sprintf('%s %s → %s', $label, self::amount($key, $old[$key] ?? null), self::amount($key, $new[$key]));
                }
            }

            return $parts === [] ? [] : [self::field('Change', implode("\n", $parts), true)];
        }
        if ($event === 'server:modpack.installed' && is_string($properties['modpack'] ?? null)) {
            return [self::field('Modpack', $properties['modpack'])];
        }

        return [];
    }

    private static function amount(string $key, mixed $value): string
    {
        if (!is_numeric($value)) {
            return '?';
        }
        $value = (int) $value;
        if ($value === 0) {
            return 'unlimited';
        }

        return match ($key) {
            'cpu' => rtrim(rtrim(number_format($value / 100, 2), '0'), '.') . ($value === 100 ? ' core' : ' cores'),
            default => rtrim(rtrim(number_format($value / 1024, 1), '0'), '.') . ' GB',
        };
    }

    private static function resources(Server $server): string
    {
        return implode(' · ', [
            self::amount('memory', $server->memory) . ' RAM',
            self::amount('cpu', $server->cpu),
            self::amount('disk', $server->disk) . ' storage',
        ]);
    }

    private static function serverLink(Server $server): string
    {
        return sprintf('[**%s**](%s)', self::escape($server->name), url('/server/' . $server->uuidShort));
    }

    /** $trusted: panel-generated text that keeps its formatting and newlines. */
    private static function field(string $name, string $value, bool $trusted = false): array
    {
        return ['name' => $name, 'value' => ($trusted ? $value : self::escape($value, 300)) ?: '—', 'inline' => true];
    }

    private static function brand(): string
    {
        return (string) config('hydrodactyl.discord.brand', 'Da Catz Hosting');
    }

    /** True the first time a key is seen within the window. */
    private static function once(string $key, int $seconds): bool
    {
        return Cache::add('discord:once:' . $key, 1, $seconds);
    }

    private static function budget(string $key, int $max, int $seconds): bool
    {
        $cacheKey = 'discord:budget:' . $key;
        Cache::add($cacheKey, 0, $seconds);

        return Cache::increment($cacheKey) <= $max;
    }
}
