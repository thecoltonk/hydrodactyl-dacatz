<?php

namespace Pterodactyl\Services\Modpacks;

use Pterodactyl\Models\Egg;

/**
 * Turns a Modrinth or CurseForge modpack version into a concrete install plan: which loader
 * egg to switch to, the egg variables and Java image to use, and the exact list
 * of server-side files to download. Everything is validated here, before any
 * server files are touched.
 */
class ModpackPlanner
{
    public const WORK_DIR = '.hydro-modpack';

    private const MAX_FILES = 2000;

    /** Preferred egg names per loader (falls back to any egg with the feature). */
    private const EGG_NAMES = ['fabric' => 'Fabric', 'quilt' => 'Quilt', 'forge' => 'Forge Minecraft', 'neoforge' => 'Neoforge'];

    public function __construct(private ModrinthModpacks $modrinth, private CurseForgeModpacks $curseforge)
    {
    }

    public function plan(string $source, ?string $projectId, string $versionId): array
    {
        if ($source === 'curseforge') {
            if ($projectId === null) {
                throw new ModpackException('A CurseForge project is required.');
            }
            $pack = $this->curseforge->resolve($projectId, $versionId);

            return $this->loaderPlan($pack['minecraft'], $pack['loader'], $pack['loader_version']) + $pack + [
                'source' => 'curseforge',
                'version_id' => $versionId,
                'project_id' => $projectId,
            ];
        }

        $version = $this->modrinth->version($versionId);
        $index = $this->modrinth->index($version['url']);

        return $this->fromIndex($index) + [
            'source' => 'modrinth',
            'version_id' => $versionId,
            'project_id' => $version['project_id'],
            'title' => $version['title'],
            'version_name' => $version['name'],
            'archive_url' => $version['url'],
            'override_dirs' => ['overrides', 'server-overrides'],
            'server_pack' => false,
            'skipped' => [],
        ];
    }

    /**
     * Pure part of planning, kept separate so it can be exercised without HTTP.
     */
    public function fromIndex(array $index): array
    {
        $dependencies = is_array($index['dependencies'] ?? null) ? $index['dependencies'] : [];
        $minecraft = $dependencies['minecraft'] ?? null;
        if (!is_string($minecraft) || !preg_match('/\A[0-9A-Za-z][0-9A-Za-z.\-_]{0,31}\z/', $minecraft)) {
            throw new ModpackException('The modpack does not declare a valid Minecraft version.');
        }

        $found = array_values(array_intersect(array_keys(ModrinthModpacks::LOADERS), array_keys($dependencies)));
        if (count($found) !== 1) {
            throw new ModpackException(count($found) === 0
                ? 'This modpack needs a loader this panel cannot install (supported: Fabric, Quilt, Forge, NeoForge).'
                : 'This modpack declares more than one mod loader.');
        }
        $loader = ModrinthModpacks::LOADERS[$found[0]];
        $loaderVersion = $dependencies[$found[0]];

        return $this->loaderPlan($minecraft, $loader, is_string($loaderVersion) ? $loaderVersion : '') + [
            'files' => $this->files($index),
        ];
    }

    /**
     * Egg, Java image and egg variables for a Minecraft + loader combination.
     */
    public function loaderPlan(string $minecraft, string $loader, string $loaderVersion): array
    {
        if (!preg_match('/\A[0-9A-Za-z][0-9A-Za-z.\-_]{0,31}\z/', $minecraft)) {
            throw new ModpackException('The modpack does not declare a valid Minecraft version.');
        }
        if (!in_array($loader, ModrinthModpacks::LOADERS, true)) {
            throw new ModpackException('This modpack needs a loader this panel cannot install (supported: Fabric, Quilt, Forge, NeoForge).');
        }
        if (!preg_match('/\A[0-9A-Za-z][0-9A-Za-z.\-+_]{0,63}\z/', $loaderVersion)) {
            throw new ModpackException('The modpack declares an invalid loader version.');
        }

        $egg = $this->egg($loader);

        return [
            'minecraft' => $minecraft,
            'loader' => $loader,
            'loader_version' => $loaderVersion,
            'egg_id' => $egg->id,
            'nest_id' => $egg->nest_id,
            'egg_name' => $egg->name,
            'docker_image' => $this->dockerImage($egg, $minecraft),
            'environment' => $this->environment($egg, $loader, $minecraft, $loaderVersion),
        ];
    }

    public function files(array $index): array
    {
        $files = [];
        foreach ((array) ($index['files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }
            // Client-only content (shaders, HUD mods...) never goes on the server.
            if (($file['env']['server'] ?? 'required') === 'unsupported') {
                continue;
            }
            $path = $file['path'] ?? null;
            if (!is_string($path) || !self::safePath($path)) {
                throw new ModpackException('The modpack contains an unsafe file path.');
            }
            $url = null;
            foreach ((array) ($file['downloads'] ?? []) as $candidate) {
                if (is_string($candidate) && self::allowedDownload($candidate)) {
                    $url = $candidate;
                    break;
                }
            }
            if ($url === null) {
                throw new ModpackException(sprintf('"%s" is hosted somewhere Modrinth does not allow.', basename($path)));
            }
            $files[] = ['path' => $path, 'url' => $url, 'size' => is_int($file['fileSize'] ?? null) ? $file['fileSize'] : null];
        }
        if (count($files) > self::MAX_FILES) {
            throw new ModpackException('This modpack has too many files to install.');
        }

        return $files;
    }

    public static function safePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 255 || str_starts_with($path, '/') || str_contains($path, '\\')
            || str_contains($path, ':') || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                return false;
            }
        }

        return !str_starts_with($path, self::WORK_DIR . '/') && $path !== self::WORK_DIR;
    }

    public static function allowedDownload(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && in_array($parts['host'] ?? null, ModrinthModpacks::FILE_HOSTS, true)
            && !isset($parts['user']) && !isset($parts['port']) && strlen($url) <= 2048;
    }

    private function egg(string $loader): Egg
    {
        $candidates = Egg::query()->get()->filter(function (Egg $egg) use ($loader) {
            $features = array_map('strtolower', array_filter((array) ($egg->inherit_features ?? []), 'is_string'));

            return in_array('mod/' . $loader, $features, true) && !in_array('plugin/sponge', $features, true);
        });
        $egg = $candidates->firstWhere('name', self::EGG_NAMES[$loader]) ?? $candidates->first();
        if (!$egg instanceof Egg) {
            throw new ModpackException(sprintf('No %s egg is installed on this panel.', ucfirst($loader)));
        }

        return $egg->loadMissing('variables');
    }

    private function environment(Egg $egg, string $loader, string $minecraft, string $loaderVersion): array
    {
        $environment = [];
        foreach ($egg->variables as $variable) {
            $environment[$variable->env_variable] = (string) ($variable->default_value ?? '');
        }

        $values = [
            'MINECRAFT_VERSION' => $minecraft,
            'MC_VERSION' => $minecraft,
            'FABRIC_VERSION' => $loader === 'fabric' ? $loaderVersion : null,
            'QUILT_VERSION' => $loader === 'quilt' ? $loaderVersion : null,
            // The Forge egg resolves an explicit version as a full Maven coordinate.
            'FORGE_VERSION' => $loader === 'forge' ? $minecraft . '-' . $loaderVersion : null,
            'NEOFORGE_VERSION' => $loader === 'neoforge' ? $loaderVersion : null,
        ];
        foreach ($values as $key => $value) {
            if ($value !== null && array_key_exists($key, $environment)) {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }

    /**
     * Pick the egg's Java image Minecraft needs for this version.
     */
    public function dockerImage(Egg $egg, string $minecraft): string
    {
        $images = (array) ($egg->docker_images ?? []);
        $java = self::javaFor($minecraft);
        foreach ([$java, 21, 17, 25] as $wanted) {
            if (isset($images['Java ' . $wanted])) {
                return $images['Java ' . $wanted];
            }
        }

        $image = reset($images);
        if (!is_string($image)) {
            throw new ModpackException('The loader egg has no Docker images configured.');
        }

        return $image;
    }

    public static function javaFor(string $minecraft): int
    {
        if (!preg_match('/\A(\d+)\.(\d+)(?:\.(\d+))?/', $minecraft, $m)) {
            return 21;
        }
        [$major, $minor, $patch] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
        if ($major >= 26) {
            return 25;
        }
        if ($major !== 1) {
            return 21;
        }

        return match (true) {
            $minor > 20 || ($minor === 20 && $patch >= 5) => 21,
            $minor >= 17 => 17,
            $minor >= 13 => 11,
            default => 8,
        };
    }
}
