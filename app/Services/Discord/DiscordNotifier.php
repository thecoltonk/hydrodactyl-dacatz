<?php

namespace Pterodactyl\Services\Discord;

use Pterodactyl\Models\Egg;
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
 * Layout: the server or account name on the small author line (linked to the
 * panel), a plain title, one plain-English sentence, resource fields in code
 * style, and an "Open in panel" link button. The color bar carries the kind:
 * green done, blue changed, amber security, red failed, gray deleted.
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

    /**
     * Activity events we post. dm: who gets a DM.
     *   owner       server owner, always
     *   owner-other server owner, unless they did it themselves
     *   account     the account the event is about
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
        'user:two-factor.create' => ['title' => '2FA turned on', 'color' => self::GREEN, 'dm' => 'account'],
        'user:two-factor.delete' => ['title' => '2FA turned off', 'color' => self::AMBER, 'dm' => 'account', 'security' => true],
        'user:api-key.create' => ['title' => 'API key created', 'color' => self::AMBER, 'dm' => 'account', 'security' => true],
        'user:ssh-key.create' => ['title' => 'SSH key added', 'color' => self::AMBER, 'dm' => 'account', 'security' => true],
        'user:discord.linked' => ['title' => 'Discord linked', 'color' => self::BLUE, 'dm' => null],
        'user:discord.unlinked' => ['title' => 'Discord unlinked', 'color' => self::GRAY, 'dm' => null],
    ];

    /** One sentence per event. {actor} / {owner} are bolded usernames. */
    private const SENTENCES = [
        'server:reinstall' => '{actor} reinstalled it.',
        'server:modpack.installed' => 'Ready to start. Players accept the EULA on first boot.',
        'server:software.change-failed' => "Switching the server software didn't finish. The panel's Operations list has the error.",
        'server:backup.fail' => "The backup didn't finish. Check that the server has storage left.",
        'server:backup.restore-failed' => "The restore didn't finish. The server's files may be partly restored.",
        'server.backup.restore-failed' => "The restore didn't finish. The server's files may be partly restored.",
        'auth:fail' => 'Someone typed the wrong password for this account.',
        'auth:sftp.fail' => 'Someone typed the wrong SFTP password for this account.',
        'auth:ip-blocked' => "An API key on this account was used from an IP address it isn't allowed from.",
        'server:sftp.denied' => "{actor} tried to open its files over SFTP without file access.",
        'user:account.password-changed' => 'The panel password was changed.',
        'user:account.email-changed' => 'The account email was changed.',
        'user:two-factor.create' => 'Two-factor authentication was turned on.',
        'user:two-factor.delete' => 'Two-factor authentication was turned off.',
        'user:api-key.create' => 'A new API key was created.',
        'user:ssh-key.create' => 'A new SSH key was added for SFTP.',
        'user:discord.linked' => 'A Discord account was linked for DMs.',
        'user:discord.unlinked' => 'The Discord account was unlinked.',
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
        $owner = $server?->user;

        $title = $rule['title'];
        $color = $rule['color'];
        $sentence = self::SENTENCES[$log->event] ?? null;
        if ($log->event === 'auth:fail') {
            // Only real accounts: typed-in usernames could be anything.
            if ($account === null) {
                return;
            }
            // The 2FA checkpoint reports failures with no username: the password was right.
            if (array_filter($properties, fn ($value) => $value !== null && $value !== '') === []) {
                $title = 'Wrong 2FA code';
                $color = self::RED;
                $sentence = "The **password was correct** but the 2FA code wasn't.";
            }
        }
        if ($log->event === 'server:settings.quick-build') {
            $sentence = $actor !== null && $actor->id !== $server?->owner_id
                ? '{actor} changed {owner}\'s server.'
                : '{owner} changed their server.';
        }
        if ($log->event === 'server:software.changed') {
            $egg = is_numeric($properties['new_egg_id'] ?? null) ? Egg::query()->find((int) $properties['new_egg_id']) : null;
            $sentence = $egg !== null ? 'Now running **' . self::escape($egg->name) . '**.' : 'The server software was switched.';
        }

        $dedupe = $rule['dedupe'] ?? null;
        if ($dedupe !== null) {
            $key = ($rule['key'] ?? $log->event) . ':' . ($server?->id ? 's' . $server->id : 'u' . ($account?->id ?? 0));
            if (!self::once($key, $dedupe)) {
                return;
            }
        }

        $embed = [
            'title' => $title,
            'color' => $color,
            'description' => $sentence === null ? null : self::fill($sentence, $actor, $owner),
            'fields' => self::extraFields($log->event, $properties),
        ];
        if ($server !== null) {
            $embed['author'] = self::serverAuthor($server);
        } elseif ($account !== null) {
            $embed['author'] = ['name' => self::plain($account->username)];
        }
        if ($actor !== null && $account !== null && $actor->id !== $account->id) {
            $embed['fields'][] = self::field('By', $actor->username);
        }
        if ($server !== null && $color === self::RED) {
            $embed['fields'][] = self::field('Owner', $owner?->username ?? 'unknown');
        }
        if ($dedupe !== null && ($rule['security'] ?? false)) {
            $embed['footer'] = ['text' => self::brand() . ' · repeats hidden for ' . intdiv($dedupe, 60) . ' min'];
        }

        $button = null;
        if ($server !== null) {
            $button = str_contains($log->event, 'backup')
                ? ['Open backups', self::serverUrl($server, '/backups')]
                : ['Open in panel', self::serverUrl($server)];
        }

        $recipient = match ($rule['dm']) {
            'owner' => $owner,
            'owner-other' => $server !== null && $actor?->id !== $server->owner_id ? $owner : null,
            'account' => $account,
            default => null,
        };

        self::send($embed, $recipient, $rule['security'] ?? false, true, $button);
    }

    public static function serverCreated(Server $server, ?User $actor): void
    {
        if (!self::enabled()) {
            return;
        }
        $owner = $server->user;
        $byOther = $actor !== null && $actor->id !== $server->owner_id;

        $fields = self::resourceFields($server);
        if ($server->egg !== null) {
            $fields[] = self::field('Software', $server->egg->name, full: true);
        }

        self::send([
            'author' => self::serverAuthor($server),
            'title' => 'Server created',
            'color' => self::GREEN,
            'description' => self::fill($byOther ? '{actor} made it for {owner}.' : '{owner} made a new server.', $actor, $owner),
            'fields' => $fields,
        ], $byOther ? $owner : null, false, true, ['Open in panel', self::serverUrl($server)]);
    }

    public static function serverDeleted(Server $server, ?User $actor): void
    {
        if (!self::enabled()) {
            return;
        }
        $owner = $server->user;
        $byOther = $actor !== null && $actor->id !== $server->owner_id;

        self::send([
            'author' => ['name' => self::plain($server->name)],
            'title' => 'Server deleted',
            'color' => self::GRAY,
            'description' => self::fill($byOther ? '{actor} deleted {owner}\'s server.' : 'Deleted by its owner, {owner}.', $actor, $owner),
        ], $byOther ? $owner : null);
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
                    'author' => self::serverAuthor($server),
                    'title' => 'Your server is ready',
                    'color' => self::GREEN,
                    'description' => "It finished installing. Hit start whenever you're ready.",
                    'fields' => self::resourceFields($server),
                ], $server->user, false, false, ['Open in panel', self::serverUrl($server)]);
            }

            return;
        }

        self::send([
            'author' => self::serverAuthor($server),
            'title' => $reinstall ? 'Reinstall failed' : 'Install failed',
            'color' => self::RED,
            'description' => self::fill("{owner}'s server didn't install. The console has the install log.", null, $server->user),
        ], $server->user, false, true, ['Open console', self::serverUrl($server)]);
    }

    /** A daemon-side job (backups on Elytra) ended in failure. */
    public static function jobFailed(Server $server, string $jobType): void
    {
        if (!self::enabled() || !self::once($jobType . ':s' . $server->id, 120)) {
            return;
        }

        [$title, $sentence] = match ($jobType) {
            'backup_create' => ['Backup failed', "The backup didn't finish. Check that the server has storage left."],
            'backup_restore' => ['Backup restore failed', "The restore didn't finish. The server's files may be partly restored."],
            'backup_delete' => ['Backup delete failed', "A backup couldn't be deleted, so it may still use storage."],
            default => [ucfirst(str_replace('_', ' ', $jobType)) . ' failed', "The daemon couldn't finish this job."],
        };
        self::send([
            'author' => self::serverAuthor($server),
            'title' => $title,
            'color' => self::RED,
            'description' => $sentence,
            'fields' => [self::field('Owner', $server->user?->username ?? 'unknown')],
        ], $server->user, false, true, ['Open backups', self::serverUrl($server, '/backups')]);
    }

    public static function modpackFailed(Server $server, string $modpack): void
    {
        if (!self::enabled()) {
            return;
        }
        self::send([
            'author' => self::serverAuthor($server),
            'title' => 'Modpack install failed',
            'color' => self::RED,
            'description' => "The install stopped partway. The panel's Operations list has the error.",
            'fields' => [self::field('Modpack', $modpack, full: true)],
        ], $server->user, false, true, ['Open in panel', self::serverUrl($server)]);
    }

    /** Linked-account welcome, sent right away so the user knows DMs work. */
    public static function linkedMessage(User $user): array
    {
        $embed = [
            'title' => "You're linked",
            'color' => self::GREEN,
            'description' => 'This Discord account is now linked to **' . self::escape($user->username) . '** on ' . self::escape(self::brand()) . '.',
            'fields' => [
                self::field(
                    "You'll hear about",
                    'Your servers finishing installs, failing, or being changed by an admin. Logins and password changes on your account.',
                    true,
                    true,
                ),
            ],
            'footer' => ['text' => self::brand() . ' · unlink any time in Account settings'],
        ];
        if (is_file(public_path('dacatz/aerial.webp'))) {
            $embed['image'] = ['url' => url('/dacatz/aerial.webp')];
        }

        return self::payload(self::finish($embed), ['Open the panel', url('/')]);
    }

    /**
     * Queue the feed post and the DM. Runs after the surrounding database
     * transaction commits, so rolled-back actions never get announced.
     *
     * @param array{0: string, 1: string}|null $button label and URL of a link button
     */
    public static function send(array $embed, ?User $dm = null, bool $security = false, bool $feed = true, ?array $button = null): void
    {
        $embed = self::finish($embed);
        $channel = trim((string) config('hydrodactyl.discord.channel_id'));
        $dmTarget = $dm?->discord_id ? 'user:' . $dm->discord_id : null;

        $feedPayload = self::payload($embed, $button);
        $personal = $embed;
        $dmButton = $button;
        if ($security) {
            $personal['fields'][] = self::field("Wasn't you?", 'Ask an admin to reset your password, then turn on 2FA.', true, true);
            $dmButton = ['Account settings', url('/account')];
        }
        $dmPayload = self::payload($personal, $dmButton);

        DB::afterCommit(function () use ($feedPayload, $dmPayload, $channel, $feed, $dmTarget) {
            try {
                // Hard cap so a flood (scripted login attempts, mass deletes) can't spam the channel.
                if ($feed && $channel !== '' && self::budget('feed', 30, 300)) {
                    SendDiscordMessage::dispatch('channel:' . $channel, $feedPayload);
                }
                if ($dmTarget !== null && self::budget($dmTarget, 10, 300)) {
                    SendDiscordMessage::dispatch($dmTarget, $dmPayload);
                }
            } catch (\Throwable $exception) {
                // Discord must never break the action that triggered it.
                Log::warning('Could not queue Discord message', ['error' => $exception->getMessage()]);
            }
        });
    }

    /** Markdown-escaped, single-line text for descriptions and field values. */
    public static function escape(string $text, int $max = 100): string
    {
        return preg_replace('/([\\\\*_~`|>#\[\]()<@])/', '\\\\$1', self::plain($text, $max)) ?? '';
    }

    /** Single-line text for places Discord doesn't render markdown (author, title). */
    private static function plain(string $text, int $max = 100): string
    {
        $text = preg_replace('/[\p{C}]+/u', ' ', $text) ?? '';

        return mb_strimwidth(trim($text), 0, $max, '…');
    }

    private static function fill(string $sentence, ?User $actor, ?User $owner): string
    {
        return strtr($sentence, [
            '{actor}' => '**' . self::escape($actor?->username ?? 'The panel') . '**',
            '{owner}' => '**' . self::escape($owner?->username ?? 'unknown') . '**',
        ]);
    }

    private static function finish(array $embed): array
    {
        $embed['footer'] ??= ['text' => self::brand()];
        $embed['timestamp'] = now()->toIso8601String();
        if (($embed['fields'] ?? []) === []) {
            unset($embed['fields']);
        }
        if (($embed['description'] ?? null) === null) {
            unset($embed['description']);
        }

        return $embed;
    }

    /** Message body; link buttons only on https panels (Discord rejects other URLs). */
    private static function payload(array $embed, ?array $button): array
    {
        $payload = ['embeds' => [$embed]];
        if ($button !== null && str_starts_with($button[1], 'https://')) {
            $payload['components'] = [['type' => 1, 'components' => [['type' => 2, 'style' => 5, 'label' => $button[0], 'url' => $button[1]]]]];
        }

        return $payload;
    }

    private static function extraFields(string $event, array $properties): array
    {
        if ($event === 'server:settings.quick-build') {
            $old = (array) ($properties['old'] ?? []);
            $new = (array) ($properties['new'] ?? []);
            $fields = [];
            foreach (['memory' => 'RAM', 'cpu' => 'CPU', 'disk' => 'Storage'] as $key => $label) {
                if (isset($new[$key]) && ($old[$key] ?? null) !== $new[$key]) {
                    $fields[] = self::field($label, self::code(self::amount($key, $old[$key] ?? null)) . ' → ' . self::code(self::amount($key, $new[$key])), true);
                }
            }

            return $fields;
        }
        if ($event === 'server:modpack.installed' && is_string($properties['modpack'] ?? null)) {
            return [self::field('Modpack', $properties['modpack'], full: true)];
        }

        return [];
    }

    private static function resourceFields(Server $server): array
    {
        return [
            self::field('RAM', self::code(self::amount('memory', $server->memory)), true),
            self::field('CPU', self::code(self::amount('cpu', $server->cpu)), true),
            self::field('Storage', self::code(self::amount('disk', $server->disk)), true),
        ];
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

    private static function code(string $text): string
    {
        return '`' . str_replace('`', "'", $text) . '`';
    }

    private static function serverAuthor(Server $server): array
    {
        return ['name' => self::plain($server->name), 'url' => self::serverUrl($server)];
    }

    private static function serverUrl(Server $server, string $path = ''): string
    {
        return url('/server/' . $server->uuidShort . $path);
    }

    /**
     * @param bool $trusted panel-generated text that keeps its formatting
     * @param bool $full    take the whole row instead of a column
     */
    private static function field(string $name, string $value, bool $trusted = false, bool $full = false): array
    {
        return ['name' => $name, 'value' => ($trusted ? $value : self::escape($value, 300)) ?: '—', 'inline' => !$full];
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
