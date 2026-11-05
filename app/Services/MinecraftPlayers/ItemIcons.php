<?php

namespace Pterodactyl\Services\MinecraftPlayers;

use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Jobs\Server\IndexItemIconsJob;

/**
 * Icons for modded items, taken from the mod jars on the server itself.
 *
 * An index (namespace => jars) is built once in the background by reading
 * each jar in /mods; after that an icon request opens the one jar that owns
 * the namespace, follows the item's model to its texture, and caches the PNG.
 */
class ItemIcons
{
    public const MAX_JAR = 96 * 1024 * 1024;

    private const NAME = '/\A([a-z0-9_.-]{1,64}):([a-z0-9_.\/-]{1,96})\z/';

    public function __construct(private PlayerFiles $files)
    {
    }

    /**
     * PNG bytes, or a status: 'missing' (no icon in any mod) or 'pending' (index still building).
     */
    public function icon(Server $server, string $id): string
    {
        if (!preg_match(self::NAME, $id, $match) || $match[1] === 'minecraft' || str_contains($match[2], '..')) {
            return 'missing';
        }
        [, $namespace, $name] = $match;

        $index = $this->index($server);
        if ($index === null) {
            return 'pending';
        }
        $key = 'mc-item-icon:' . $server->uuid . ':' . $index['fingerprint'] . ':' . $id;
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached === '' ? 'missing' : base64_decode($cached);
        }

        $png = null;
        foreach ($index['map'][$namespace] ?? [] as $jar) {
            try {
                $png = $this->fromJar($server, $jar, $namespace, $name);
            } catch (\Throwable $exception) {
                Log::debug('Item icon lookup failed', ['jar' => $jar, 'id' => $id, 'error' => $exception->getMessage()]);
            }
            if ($png !== null) {
                break;
            }
        }
        Cache::put($key, $png === null ? '' : base64_encode($png), now()->addDays($png === null ? 1 : 30));

        return $png ?? 'missing';
    }

    /**
     * The namespace index for the current set of mods, or null while it is being
     * built (a background job is queued the first time).
     */
    public function index(Server $server): ?array
    {
        $fingerprint = Cache::remember('mc-item-icons:fp:' . $server->uuid, 60, fn () => $this->fingerprint($server));
        if ($fingerprint === null) {
            return ['fingerprint' => 'none', 'map' => []];
        }
        $index = Cache::get('mc-item-icons:index:' . $server->uuid);
        if (is_array($index) && ($index['fingerprint'] ?? null) === $fingerprint) {
            return $index;
        }
        if (Cache::add('mc-item-icons:building:' . $server->uuid, 1, 600)) {
            IndexItemIconsJob::dispatch($server->id);
        }

        return null;
    }

    /**
     * Build the namespace index from the queue. Works in ~60s slices (the queue
     * re-runs anything longer than 90s), saving progress and returning false
     * when more jars are left.
     */
    public function build(Server $server): bool
    {
        $fingerprint = $this->fingerprint($server) ?? 'none';
        $progressKey = 'mc-item-icons:progress:' . $server->uuid;
        $progress = Cache::get($progressKey);
        if (!is_array($progress) || ($progress['fingerprint'] ?? null) !== $fingerprint) {
            $progress = ['fingerprint' => $fingerprint, 'done' => [], 'map' => []];
        }
        $deadline = microtime(true) + 60;
        $path = tempnam(sys_get_temp_dir(), 'mcjar');
        $finished = true;
        foreach ($this->jars($server) as $jar) {
            if (isset($progress['done'][$jar['name']])) {
                continue;
            }
            if (microtime(true) > $deadline) {
                $finished = false;
                break;
            }
            $progress['done'][$jar['name']] = true;
            // One jar at a time in a temp file, so indexing never needs much disk.
            try {
                if (!$this->files->download($server, '/mods/' . $jar['name'], $path, self::MAX_JAR)) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
                continue;
            }
            $namespaces = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (preg_match('~\Aassets/([a-z0-9_.-]{1,64})/(?:textures|models|items)/~', $entry, $match) && $match[1] !== 'minecraft') {
                    $namespaces[$match[1]] = true;
                }
            }
            $zip->close();
            foreach (array_keys($namespaces) as $namespace) {
                $progress['map'][$namespace][] = $jar['name'];
            }
        }
        @unlink($path);

        if (!$finished) {
            Cache::put($progressKey, $progress, now()->addHour());
            Cache::put('mc-item-icons:building:' . $server->uuid, 1, 600);

            return false;
        }
        Cache::put('mc-item-icons:index:' . $server->uuid, ['fingerprint' => $fingerprint, 'map' => $progress['map']], now()->addDays(30));
        Cache::forget($progressKey);
        Cache::forget('mc-item-icons:building:' . $server->uuid);

        return true;
    }

    private function fromJar(Server $server, string $jar, string $namespace, string $name): ?string
    {
        $path = $this->localJar($server, $jar);
        if ($path === null) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return null;
        }
        try {
            $texture = $this->textureFor($zip, $namespace, $name);
            $png = $texture === null ? null : $zip->getFromName($texture);

            return is_string($png) && $png !== '' ? $this->square($png) : null;
        } finally {
            $zip->close();
        }
    }

    /** Follow items/ definition -> model -> parents -> texture path inside the jar. */
    private function textureFor(\ZipArchive $zip, string $namespace, string $name): ?string
    {
        $model = $namespace . ':item/' . $name;
        $definition = $this->json($zip, "assets/{$namespace}/items/{$name}.json");
        if ($definition !== null) {
            $model = $this->firstModel($definition['model'] ?? null) ?? $model;
        }

        for ($depth = 0; $depth < 6 && $model !== null; $depth++) {
            [$modelNs, $modelPath] = $this->split($model);
            $json = $this->json($zip, "assets/{$modelNs}/models/{$modelPath}.json");
            if ($json === null) {
                break;
            }
            $textures = is_array($json['textures'] ?? null) ? $json['textures'] : [];
            foreach (['layer0', 'all', 'side', 'front', 'top', 'texture', 'end', 'cross', 'particle'] as $key) {
                $ref = $textures[$key] ?? null;
                if (is_string($ref) && !str_starts_with($ref, '#')) {
                    [$textureNs, $texturePath] = $this->split($ref);
                    $file = "assets/{$textureNs}/textures/{$texturePath}.png";
                    if ($zip->locateName($file) !== false) {
                        return $file;
                    }
                }
            }
            $parent = $json['parent'] ?? null;
            $model = is_string($parent) && !str_starts_with($parent, 'minecraft:') && !str_starts_with($parent, 'builtin/') && !str_starts_with($parent, 'item/') && !str_starts_with($parent, 'block/')
                ? $parent : null;
        }

        foreach (["assets/{$namespace}/textures/item/{$name}.png", "assets/{$namespace}/textures/block/{$name}.png", "assets/{$namespace}/textures/items/{$name}.png", "assets/{$namespace}/textures/blocks/{$name}.png"] as $file) {
            if ($zip->locateName($file) !== false) {
                return $file;
            }
        }

        return null;
    }

    /** First plain model id inside an items/ definition (select/condition/etc.). */
    private function firstModel(mixed $node, int $depth = 0): ?string
    {
        if ($depth > 8 || !is_array($node)) {
            return null;
        }
        if (is_string($node['model'] ?? null) && in_array($node['type'] ?? 'minecraft:model', ['minecraft:model', 'model'], true)) {
            return $node['model'];
        }
        foreach (['model', 'fallback', 'on_false', 'on_true', 'cases', 'entries', 'models'] as $key) {
            if (!isset($node[$key])) {
                continue;
            }
            $children = is_array($node[$key]) && array_is_list($node[$key]) ? $node[$key] : [$node[$key]];
            foreach ($children as $child) {
                if ($found = $this->firstModel($child, $depth + 1)) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function split(string $ref): array
    {
        $ref = strtolower($ref);
        [$ns, $path] = str_contains($ref, ':') ? explode(':', $ref, 2) : ['minecraft', $ref];
        if (!preg_match('~\A[a-z0-9_.-]{1,64}\z~', $ns) || !preg_match('~\A[a-z0-9_./-]{1,160}\z~', $path) || str_contains($path, '..')) {
            return ['minecraft', 'invalid'];
        }

        return [$ns, $path];
    }

    private function json(\ZipArchive $zip, string $file): ?array
    {
        $raw = $zip->getFromName($file);
        if (!is_string($raw) || strlen($raw) > 262144) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** Animated textures are vertical strips; keep the first frame. */
    private function square(string $png): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $png;
        }
        $image = @imagecreatefromstring($png);
        if ($image === false) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width > 512 || $width < 1) {
            imagedestroy($image);

            return null;
        }
        if ($height <= $width) {
            imagedestroy($image);

            return $png;
        }
        $frame = imagecreatetruecolor($width, $width);
        imagesavealpha($frame, true);
        imagefill($frame, 0, 0, imagecolorallocatealpha($frame, 0, 0, 0, 127));
        imagecopy($frame, $image, 0, 0, 0, 0, $width, $width);
        ob_start();
        imagepng($frame);
        $out = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($frame);

        return $out;
    }

    /** Jars in /mods with size and modified time. */
    private function jars(Server $server): array
    {
        try {
            $entries = $this->files->directory($server, '/mods');
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($entry) => is_array($entry) ? [
            'name' => (string) ($entry['name'] ?? ''),
            'size' => (int) ($entry['size'] ?? 0),
            'modified' => (string) ($entry['modified'] ?? ''),
            'file' => (bool) ($entry['file'] ?? !($entry['directory'] ?? false)),
        ] : null, $entries), fn ($jar) => $jar !== null && $jar['file'] && str_ends_with(strtolower($jar['name']), '.jar')
            && !str_contains($jar['name'], '/') && $jar['size'] > 0 && $jar['size'] <= self::MAX_JAR));
    }

    private function fingerprint(Server $server): ?string
    {
        $jars = $this->jars($server);
        if ($jars === []) {
            return null;
        }
        usort($jars, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return sha1(json_encode(array_map(fn ($jar) => [$jar['name'], $jar['size'], $jar['modified']], $jars)));
    }

    /**
     * A local copy of one jar, kept for a while so an inventory full of items
     * from the same mod only downloads it once.
     */
    private function localJar(Server $server, string $jar): ?string
    {
        $dir = storage_path('app/mc-item-icons/' . $server->uuid);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $path = $dir . '/' . sha1($jar) . '.jar';
        if (is_file($path) && filemtime($path) > time() - 1800) {
            return $path;
        }
        $this->sweep($dir);
        try {
            return $this->files->download($server, '/mods/' . $jar, $path, self::MAX_JAR) ? $path : null;
        } catch (\Throwable) {
            @unlink($path);

            return null;
        }
    }

    /** Drop cached jars older than 30 minutes. */
    private function sweep(string $dir): void
    {
        foreach (glob($dir . '/*.jar') ?: [] as $file) {
            if (filemtime($file) < time() - 1800) {
                @unlink($file);
            }
        }
    }
}
