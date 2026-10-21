<?php

namespace Pterodactyl\Services\Marketplace;

use RuntimeException;

/**
 * Thrown by marketplace adapters when an upstream provider fails or returns a
 * response we cannot understand. The controller converts this into a 502 JSON
 * response so the frontend can show a per-source error toast.
 */
class MarketplaceException extends RuntimeException
{
    /** Safe, actionable text to show the user (null = show a generic error). */
    public ?string $userMessage = null;

    public static function user(string $source, string $message, ?\Throwable $previous = null): self
    {
        $exception = self::upstream($source, $message, $previous);
        $exception->userMessage = $message;

        return $exception;
    }

    public static function upstream(string $source, string $message, ?\Throwable $previous = null): self
    {
        return new self(sprintf('[%s] %s', $source, $message), 0, $previous);
    }
}
