<?php

namespace Pterodactyl\Services\Modpacks;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A user-facing modpack problem (bad pack, unsupported loader, upstream error).
 * Rendered to the client as a 422 with the message intact.
 */
class ModpackException extends HttpException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(422, $message, $previous, [], $code);
    }
}
