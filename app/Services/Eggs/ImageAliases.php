<?php

namespace Pterodactyl\Services\Eggs;

/**
 * Swaps Docker images that were deleted upstream for working equivalents,
 * at the moment the daemon asks for them. This keeps installs working even
 * when an egg (bundled, re-seeded on update, or imported) still names a dead
 * image.
 *
 * Extra swaps can be added without a code change via IMAGE_ALIASES in the
 * panel's environment, as JSON: {"old/image:tag": "new/image:tag"}.
 */
class ImageAliases
{
    /** Images that were removed from their registry, and what to use instead. */
    private const FIXED = [
        'ghcr.io/ptero-eggs/yolks:java_18' => 'ghcr.io/pterodactyl/yolks:java_18',
    ];

    /** Java versions Eclipse Temurin publishes images for. */
    private const TEMURIN = [8, 11, 17, 21, 25];

    public static function resolve(?string $image): ?string
    {
        if ($image === null) {
            return null;
        }
        $image = trim($image);
        if ($image === '') {
            return $image;
        }

        $custom = config('hydrodactyl.image_aliases', []);
        if (is_array($custom) && is_string($custom[$image] ?? null) && trim($custom[$image]) !== '') {
            return trim($custom[$image]);
        }
        if (isset(self::FIXED[$image])) {
            return self::FIXED[$image];
        }

        // Docker Hub deleted the official openjdk images. Eclipse Temurin is the
        // maintained build of the same Java, also Debian/Ubuntu based.
        if (preg_match('~\A(?:docker\.io/)?(?:library/)?openjdk:(\d+)([^@]*)\z~', $image, $match)) {
            $variant = str_contains($match[2], 'jre') ? 'jre' : 'jdk';

            return 'eclipse-temurin:' . self::temurin((int) $match[1]) . '-' . $variant;
        }

        return $image;
    }

    /** The nearest Temurin release at or above the requested Java version. */
    private static function temurin(int $version): int
    {
        foreach (self::TEMURIN as $available) {
            if ($version <= $available) {
                return $available;
            }
        }

        return $version;
    }
}
