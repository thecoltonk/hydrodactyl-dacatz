<?php

namespace Pterodactyl\Services\Modpacks;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin, cached client for Modrinth modpacks (.mrpack).
 *
 * All upstream calls are made by the panel (never the browser) so requests carry
 * a proper User-Agent, and only Modrinth-hosted pack archives are accepted.
 */
class ModrinthModpacks
{
    private const API = 'https://api.modrinth.com/v2';

    /** Hosts Modrinth allows inside modrinth.index.json download lists. */
    public const FILE_HOSTS = ['cdn.modrinth.com', 'github.com', 'raw.githubusercontent.com', 'gitlab.com'];

    private const PACK_LIMIT = 268435456; // 256 MiB

    private const INDEX_LIMIT = 16777216; // 16 MiB

    public const LOADERS = ['fabric-loader' => 'fabric', 'quilt-loader' => 'quilt', 'forge' => 'forge', 'neoforge' => 'neoforge'];

    public function search(string $query, int $offset = 0, ?string $loader = null): array
    {
        $facets = [['project_type:modpack'], ['server_side:required', 'server_side:optional']];
        if ($loader !== null && in_array($loader, self::LOADERS, true)) {
            $facets[] = ['categories:' . $loader];
        }

        $data = $this->get('/search', [
            'query' => mb_substr($query, 0, 100),
            'facets' => json_encode($facets),
            'index' => $query === '' ? 'downloads' : 'relevance',
            'limit' => 20,
            'offset' => max(0, min($offset, 1000)),
        ]);

        $hits = [];
        foreach ((array) ($data['hits'] ?? []) as $hit) {
            if (!is_array($hit) || !is_string($hit['project_id'] ?? null)) {
                continue;
            }
            $hits[] = [
                'id' => $hit['project_id'],
                'slug' => (string) ($hit['slug'] ?? ''),
                'title' => (string) ($hit['title'] ?? 'Untitled'),
                'description' => (string) ($hit['description'] ?? ''),
                'author' => (string) ($hit['author'] ?? ''),
                'icon' => $this->httpsUrl($hit['icon_url'] ?? null),
                'downloads' => (int) ($hit['downloads'] ?? 0),
                'loaders' => array_values(array_intersect((array) ($hit['categories'] ?? []), self::LOADERS)),
                'game_versions' => array_slice(array_values(array_filter((array) ($hit['versions'] ?? []), 'is_string')), -6),
                'url' => isset($hit['slug']) ? 'https://modrinth.com/modpack/' . rawurlencode((string) $hit['slug']) : null,
            ];
        }

        return ['hits' => $hits, 'total' => (int) ($data['total_hits'] ?? count($hits))];
    }

    public function versions(string $projectId): array
    {
        $this->assertId($projectId);

        $versions = [];
        foreach ($this->get('/project/' . $projectId . '/version') as $version) {
            if (!is_array($version) || !is_string($version['id'] ?? null) || $this->packFile($version) === null) {
                continue;
            }
            $versions[] = [
                'id' => $version['id'],
                'name' => (string) ($version['name'] ?? $version['version_number'] ?? $version['id']),
                'version_number' => (string) ($version['version_number'] ?? ''),
                'game_versions' => array_values(array_filter((array) ($version['game_versions'] ?? []), 'is_string')),
                'loaders' => array_values(array_intersect((array) ($version['loaders'] ?? []), self::LOADERS)),
                'type' => (string) ($version['version_type'] ?? 'release'),
                'date' => is_string($version['date_published'] ?? null) ? $version['date_published'] : null,
            ];
        }

        return array_slice($versions, 0, 100);
    }

    /**
     * @return array{project_id: string, name: string, title: string, url: string, size: int|null}
     */
    public function version(string $versionId): array
    {
        $this->assertId($versionId);
        $version = $this->get('/version/' . $versionId);
        $file = $this->packFile($version);
        if ($file === null || !is_string($version['project_id'] ?? null)) {
            throw new ModpackException('That version has no installable .mrpack file.');
        }
        $project = $this->get('/project/' . $version['project_id']);

        return [
            'project_id' => $version['project_id'],
            'name' => (string) ($version['name'] ?? $version['version_number'] ?? $versionId),
            'title' => (string) ($project['title'] ?? 'Modpack'),
            'url' => $file['url'],
            'size' => isset($file['size']) ? (int) $file['size'] : null,
        ];
    }

    /**
     * Download a pack archive (bounded) and return its decoded modrinth.index.json.
     */
    public function index(string $url): array
    {
        if (!$this->isModrinthCdn($url)) {
            throw new ModpackException('Modpack archives must come from cdn.modrinth.com.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mrpack');
        try {
            try {
                $response = Http::withHeaders(['User-Agent' => $this->userAgent()])
                    ->withOptions([
                        'sink' => $path,
                        'allow_redirects' => false,
                        'progress' => function ($total, $downloaded) {
                            if ($total > self::PACK_LIMIT || $downloaded > self::PACK_LIMIT) {
                                throw new ModpackException('This modpack archive is larger than 256 MiB.');
                            }
                        },
                    ])
                    ->timeout(120)
                    ->get($url);
            } catch (ConnectionException|GuzzleException $exception) {
                throw new ModpackException('Could not download the modpack archive.', 0, $exception);
            }
            if ($response->failed()) {
                throw new ModpackException(sprintf('Modrinth responded with HTTP %d for the archive.', $response->status()));
            }

            return self::readIndex($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Read and validate modrinth.index.json from a local .mrpack.
     */
    public static function readIndex(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new ModpackException('The modpack archive is not a valid zip file.');
        }
        try {
            $stat = $zip->statName('modrinth.index.json');
            if ($stat === false || $stat['size'] > self::INDEX_LIMIT) {
                throw new ModpackException('The modpack archive has no usable modrinth.index.json.');
            }
            $json = $zip->getFromName('modrinth.index.json');
        } finally {
            $zip->close();
        }

        try {
            $index = json_decode((string) $json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ModpackException('The modpack index is not valid JSON.', 0, $exception);
        }
        if (!is_array($index) || ($index['formatVersion'] ?? null) !== 1 || ($index['game'] ?? null) !== 'minecraft') {
            throw new ModpackException('Only Minecraft modpacks in Modrinth format 1 are supported.');
        }

        return $index;
    }

    public function isModrinthCdn(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'cdn.modrinth.com'
            && !isset($parts['user']) && !isset($parts['port']);
    }

    private function packFile(array $version): ?array
    {
        $files = array_values(array_filter((array) ($version['files'] ?? []), fn ($file) => is_array($file)
            && is_string($file['url'] ?? null) && str_ends_with(strtolower((string) ($file['filename'] ?? '')), '.mrpack')
            && $this->isModrinthCdn($file['url'])));
        foreach ($files as $file) {
            if (!empty($file['primary'])) {
                return $file;
            }
        }

        return $files[0] ?? null;
    }

    private function assertId(string $id): void
    {
        if (!preg_match('/\A[A-Za-z0-9]{1,64}\z/', $id)) {
            throw new ModpackException('Invalid Modrinth identifier.');
        }
    }

    private function httpsUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && strlen($url) < 2048 ? $url : null;
    }

    private function userAgent(): string
    {
        return 'hydrodactyl/' . config('app.version', 'unknown') . ' modpack-installer (hydrodactyl.dev)';
    }

    private function get(string $path, array $query = []): array
    {
        $key = 'modpacks:modrinth:' . sha1($path . '?' . http_build_query($query));

        return Cache::remember($key, 300, function () use ($path, $query): array {
            try {
                $response = Http::withHeaders(['User-Agent' => $this->userAgent(), 'Accept' => 'application/json'])
                    ->timeout(15)
                    ->get(self::API . $path, $query);
            } catch (ConnectionException $exception) {
                throw new ModpackException('Could not reach Modrinth.', 0, $exception);
            }
            if ($response->status() === 404) {
                throw new ModpackException('That modpack or version was not found on Modrinth.');
            }
            if ($response->failed()) {
                throw new ModpackException(sprintf('Modrinth responded with HTTP %d.', $response->status()));
            }

            return (array) ($response->json() ?? []);
        });
    }
}
