<?php

namespace Pterodactyl\Services\Modpacks;

use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Elytra\DaemonFileRepository as ElytraFiles;
use Pterodactyl\Repositories\Elytra\DaemonPowerRepository as ElytraPower;
use Pterodactyl\Repositories\Wings\DaemonFileRepository as WingsFiles;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository as WingsPower;

/**
 * The handful of daemon file/power calls the installer needs, routed to the
 * Wings or Elytra repository based on the node's daemon type.
 */
class ModpackDaemon
{
    public function kill(Server $server): void
    {
        $repository = $this->wings($server) ? app(WingsPower::class) : app(ElytraPower::class);
        try {
            $repository->setServer($server)->send('kill');
        } catch (DaemonConnectionException) {
            // Already offline servers can reject power actions; that is fine here.
        }
    }

    public function list(Server $server, string $directory): array
    {
        return $this->files($server)->getDirectory($directory);
    }

    public function delete(Server $server, string $root, array $names): void
    {
        if ($names !== []) {
            $this->files($server)->deleteFiles($root, array_values($names));
        }
    }

    public function mkdir(Server $server, string $path): void
    {
        try {
            $this->files($server)->createDirectory($path, '/');
        } catch (DaemonConnectionException) {
            // Already exists.
        }
    }

    public function rename(Server $server, string $from, string $to): void
    {
        $this->files($server)->renameFiles('/', [['from' => $from, 'to' => $to]]);
    }

    public function decompress(Server $server, string $root, string $file): void
    {
        $this->files($server)->decompressFile($root, $file);
    }

    /**
     * Have the daemon download a URL into the server and wait for it to finish.
     */
    public function pull(Server $server, string $url, string $directory, string $filename): void
    {
        try {
            $this->files($server)->getHttpClient()->post(
                sprintf('/api/servers/%s/files/pull', $server->uuid),
                [
                    'json' => ['url' => $url, 'root' => $directory, 'file_name' => $filename, 'foreground' => true],
                    'timeout' => 600,
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }

    private function files(Server $server): WingsFiles|ElytraFiles
    {
        $repository = $this->wings($server) ? app(WingsFiles::class) : app(ElytraFiles::class);

        return $repository->setServer($server);
    }

    private function wings(Server $server): bool
    {
        return strtolower((string) $server->node->daemonType) === 'wings';
    }
}
