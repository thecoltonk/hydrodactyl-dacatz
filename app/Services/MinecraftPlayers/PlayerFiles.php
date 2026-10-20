<?php

/*
 * Ported from Aquadactyl/MCPlayersJava (https://github.com/Aquadactyl/MCPlayersJava).
 * Copyright (c) Aquadactyl contributors. Licensed under the BSD 2-Clause License;
 * see licenses/MCPlayersJava.LICENSE for the full text.
 */

namespace Pterodactyl\Services\MinecraftPlayers;

use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Elytra\DaemonCommandRepository as ElytraCommandRepository;
use Pterodactyl\Repositories\Elytra\DaemonFileRepository as ElytraFileRepository;
use Pterodactyl\Repositories\Wings\DaemonCommandRepository as WingsCommandRepository;
use Pterodactyl\Repositories\Wings\DaemonFileRepository as WingsFileRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Bounded, daemon-agnostic access to a server's files and console.
 *
 * Wings and Elytra expose the same file-content, directory-listing and command
 * endpoints, so the matching repository is chosen from the node's daemon type.
 */
class PlayerFiles
{
    /**
     * Read a file through the daemon, aborting once it grows past $limit bytes.
     *
     * @throws DaemonConnectionException
     */
    public function read(Server $server, string $path, int $limit): string
    {
        try {
            $response = $this->files($server)->getHttpClient()->get(
                '/api/servers/' . $server->uuid . '/files/contents',
                ['query' => ['file' => $path], 'stream' => true, 'timeout' => 5, 'connect_timeout' => 2, 'read_timeout' => 5]
            );
            $stream = $response->getBody();
            $content = '';
            try {
                while (!$stream->eof()) {
                    $content .= $stream->read(min(8192, $limit + 1 - strlen($content)));
                    if (strlen($content) > $limit) {
                        throw new HttpException(413, 'This saved player file is too large to inspect.');
                    }
                }
            } finally {
                $stream->close();
            }

            return $content;
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }

    /**
     * @throws DaemonConnectionException
     */
    public function directory(Server $server, string $path): array
    {
        return $this->files($server)->getDirectory($path);
    }

    /**
     * @throws DaemonConnectionException
     */
    public function command(Server $server, string $command): void
    {
        $repository = $this->isWings($server)
            ? app(WingsCommandRepository::class)
            : app(ElytraCommandRepository::class);

        $repository->setServer($server)->send($command);
    }

    private function files(Server $server): WingsFileRepository|ElytraFileRepository
    {
        $repository = $this->isWings($server)
            ? app(WingsFileRepository::class)
            : app(ElytraFileRepository::class);

        return $repository->setServer($server);
    }

    private function isWings(Server $server): bool
    {
        return strtolower((string) $server->node->daemonType) === 'wings';
    }
}
