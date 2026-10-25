<?php

namespace Pterodactyl\Services\Discord;

class DiscordException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $permanent = false,
        public readonly ?int $retryAfter = null,
        int $code = 0,
    ) {
        parent::__construct($message, $code);
    }
}
