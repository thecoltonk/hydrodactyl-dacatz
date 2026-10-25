<?php

namespace Pterodactyl\Jobs\Server;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Jobs\Job;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerOperation;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Modpacks\ModpackDaemon;
use Pterodactyl\Services\Modpacks\ModpackPlanner;
use Pterodactyl\Services\Servers\ReinstallServerService;
use Pterodactyl\Services\Servers\StartupModificationService;

/**
 * Fresh-installs a Modrinth modpack onto a server:
 *
 *  1. stop the server and wipe its files
 *  2. switch to the pack's loader egg (Fabric/Quilt/Forge/NeoForge) with the
 *     exact Minecraft + loader versions and a matching Java image, then reinstall
 *  3. download every server-side file listed in the pack
 *  4. apply the pack's overrides/ and server-overrides/ folders
 *
 * Progress is reported through the ServerOperation record.
 */
class InstallModpackJob extends Job implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public $timeout = 3600;
    public $tries = 1;
    public $failOnTimeout = true;

    private const WORK = ModpackPlanner::WORK_DIR;

    public function __construct(
        public Server $server,
        public User $user,
        public array $plan,
        public string $operationId,
        // Brand-new server already created with the pack's loader egg: skip the
        // stop/wipe/egg switch/reinstall and just wait for its first install.
        public bool $fresh = false,
    ) {
        $this->queue = 'standard';
    }

    public function handle(
        ModpackDaemon $daemon,
        StartupModificationService $startup,
        ReinstallServerService $reinstall,
    ): void {
        $operation = ServerOperation::where('operation_id', $this->operationId)->firstOrFail();
        // Guard against the queue re-delivering a long-running job (retry_after):
        // only a pending operation may start, so a duplicate run is a no-op.
        if ($operation->status !== ServerOperation::STATUS_PENDING) {
            return;
        }
        $operation->markAsStarted();
        $label = $this->plan['title'] . ' ' . $this->plan['version_name'];

        try {
            if ($this->fresh) {
                $operation->updateProgress(sprintf(
                    'Installing %s %s for Minecraft %s...',
                    ucfirst($this->plan['loader']),
                    $this->plan['loader_version'],
                    $this->plan['minecraft'],
                ));
                $server = $this->server;
                $this->waitForInstall($server);
            } else {
            $operation->updateProgress('Stopping the server...');
            $daemon->kill($this->server);
            sleep(3);

            $operation->updateProgress('Wiping server files...');
            $root = $daemon->list($this->server, '/');
            $daemon->delete($this->server, '/', array_column($root, 'name'));

            $operation->updateProgress(sprintf(
                'Installing %s %s for Minecraft %s...',
                ucfirst($this->plan['loader']),
                $this->plan['loader_version'],
                $this->plan['minecraft'],
            ));
            $egg = Egg::query()->findOrFail($this->plan['egg_id']);
            $this->server->update(['egg_id' => $egg->id, 'nest_id' => $egg->nest_id]);
            $server = $startup->setUserLevel(User::USER_LEVEL_ADMIN)->handle($this->server->refresh(), [
                'egg_id' => $egg->id,
                'startup' => $egg->startup,
                'docker_image' => $this->plan['docker_image'],
                'environment' => $this->plan['environment'],
            ]);
            $reinstall->handle($server);
            $this->waitForInstall($server);
            }

            $operation->updateProgress('Downloading the modpack archive...');
            $daemon->mkdir($server, self::WORK);
            $serverPack = (bool) ($this->plan['server_pack'] ?? false);
            if ($serverPack) {
                $operation->updateProgress('Downloading the server pack (this can take a few minutes)...');
            }
            $daemon->pull($server, $this->plan['archive_url'] ?? $this->plan['pack_url'], '/' . self::WORK, 'pack.zip');
            $daemon->decompress($server, '/' . self::WORK, 'pack.zip');

            $files = $this->plan['files'];
            $total = count($files);
            $directories = [];
            foreach ($files as $file) {
                $dir = dirname($file['path']);
                if ($dir !== '.' && !isset($directories[$dir])) {
                    $daemon->mkdir($server, $dir);
                    $directories[$dir] = true;
                }
            }
            foreach ($files as $i => $file) {
                if ($i % 5 === 0 || $i === $total - 1) {
                    $operation->updateProgress(sprintf('Downloading mods (%d/%d): %s', $i + 1, $total, basename($file['path'])));
                }
                $dir = dirname($file['path']);
                $daemon->pull($server, $file['url'], $dir === '.' ? '/' : '/' . $dir, basename($file['path']));
            }

            $operation->updateProgress('Applying configs and overrides...');
            if ($serverPack) {
                $this->merge($daemon, $server, $this->serverPackRoot($daemon, $server), '', 0, true);
            }
            foreach ((array) ($this->plan['override_dirs'] ?? ['overrides', 'server-overrides']) as $folder) {
                $this->merge($daemon, $server, self::WORK . '/' . $folder, '', 0);
            }
            $daemon->delete($server, '/', [self::WORK]);

            Activity::actor($this->user)->event('server:modpack.installed')
                ->subject($server)
                ->property(['modpack' => $label, 'project_id' => $this->plan['project_id'], 'version_id' => $this->plan['version_id']])
                ->log();

            $skipped = array_values(array_filter((array) ($this->plan['skipped'] ?? []), 'is_string'));
            $message = sprintf('%s installed%s. Start the server to accept the EULA and play.', $label, $serverPack ? ' from its server pack' : sprintf(' (%d files)', $total));
            if ($skipped !== []) {
                $message .= sprintf(
                    ' %d mod(s) block third-party downloads and must be added to /mods manually: %s.',
                    count($skipped),
                    implode(', ', array_slice($skipped, 0, 25)) . (count($skipped) > 25 ? ', ...' : ''),
                );
            }
            $operation->markAsCompleted($message);
        } catch (\Throwable $exception) {
            Log::error('Modpack install failed', ['server_id' => $this->server->id, 'error' => $exception->getMessage()]);
            $operation->markAsFailed('Modpack install failed: ' . $exception->getMessage());
            try {
                \Pterodactyl\Services\Discord\DiscordNotifier::modpackFailed($this->server, (string) ($this->plan['title'] ?? 'modpack'));
            } catch (\Throwable) {
            }
            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        ServerOperation::where('operation_id', $this->operationId)->first()
            ?->markAsFailed('Modpack install failed: ' . $exception->getMessage());
    }

    private function waitForInstall(Server $server): void
    {
        $deadline = now()->addMinutes(20);
        sleep(5);
        while (now()->lt($deadline)) {
            $status = $server->refresh()->status;
            if ($status === null) {
                return;
            }
            if (in_array($status, [Server::STATUS_INSTALL_FAILED, Server::STATUS_REINSTALL_FAILED], true)) {
                throw new \RuntimeException('The loader install script failed. Check the console for details.');
            }
            sleep(5);
        }

        throw new \RuntimeException('Timed out waiting for the loader to install.');
    }

    /**
     * Server packs are often wrapped in a single top-level folder.
     */
    private function serverPackRoot(ModpackDaemon $daemon, Server $server): string
    {
        $root = self::WORK;
        for ($i = 0; $i < 2; $i++) {
            $entries = array_values(array_filter($daemon->list($server, '/' . $root), fn ($entry) => ($entry['name'] ?? '') !== 'pack.zip'));
            $names = array_column($entries, 'name');
            if (in_array('mods', $names, true) || count($entries) !== 1 || !($entries[0]['directory'] ?? false)) {
                break;
            }
            $root .= '/' . $entries[0]['name'];
        }

        return $root;
    }

    /**
     * Move everything under $source into $target, replacing conflicting files
     * and recursing into folders that exist on both sides.
     *
     * For server packs, top-level launchers/jars/libraries are skipped so the
     * loader installed by the egg (with the pack's exact version) is kept.
     */
    private function merge(ModpackDaemon $daemon, Server $server, string $source, string $target, int $depth, bool $serverPack = false): void
    {
        if ($depth > 16) {
            return;
        }
        try {
            $entries = $daemon->list($server, '/' . $source);
        } catch (\Throwable) {
            return; // The pack has no such folder.
        }
        $existing = [];
        try {
            foreach ($daemon->list($server, '/' . $target) as $entry) {
                $existing[$entry['name']] = (bool) ($entry['directory'] ?? false);
            }
        } catch (\Throwable) {
            // Target does not exist yet.
        }

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            if ($serverPack && $depth === 0 && ($name === 'pack.zip' || $name === 'libraries' || $name === 'eula.txt'
                || preg_match('/\.(jar|sh|bat|cmd|ps1)$/i', $name))) {
                continue;
            }
            $from = $source . '/' . $name;
            $to = ltrim($target . '/' . $name, '/');
            $isDirectory = (bool) ($entry['directory'] ?? false);
            if (!array_key_exists($name, $existing)) {
                $daemon->rename($server, $from, $to);
            } elseif ($isDirectory && $existing[$name]) {
                $this->merge($daemon, $server, $from, $to, $depth + 1);
            } else {
                $daemon->delete($server, '/' . $target, [$name]);
                $daemon->rename($server, $from, $to);
            }
        }
    }
}
