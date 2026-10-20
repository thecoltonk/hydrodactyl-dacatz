<?php

namespace Pterodactyl\Services\Modpacks;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * CurseForge modpacks via the official API (needs a free key from
 * console.curseforge.com, set as CURSEFORGE_API_KEY).
 *
 * When a pack version has an author-made server pack, that is installed (it is
 * curated for servers). Otherwise the client manifest is resolved file by file,
 * skipping non-mod content and client-only files. Files whose authors disabled
 * third-party downloads are reported, never fetched around the restriction.
 */
class CurseForgeModpacks
{
    private const API = 'https://api.curseforge.com/v1';

    private const GAME_MINECRAFT = 432;

    private const CLASS_MODPACK = 4471;

    private const CLASS_MOD = 6;

    public const FILE_HOSTS = ['edge.forgecdn.net', 'mediafilez.forgecdn.net'];

    /** API modLoaderType ids. */
    private const LOADER_TYPES = ['forge' => 1, 'fabric' => 4, 'quilt' => 5, 'neoforge' => 6];

    private const PACK_LIMIT = 268435456; // 256 MiB

    public function configured(): bool
    {
        return trim((string) config('hydrodactyl.modpacks.curseforge_api_key', '')) !== '';
    }

    public function search(string $query, int $offset = 0, ?string $loader = null): array
    {
        $params = [
            'gameId' => self::GAME_MINECRAFT,
            'classId' => self::CLASS_MODPACK,
            'searchFilter' => mb_substr($query, 0, 100),
            'sortField' => 2, // popularity
            'sortOrder' => 'desc',
            'index' => max(0, min($offset, 9980)),
            'pageSize' => 20,
        ];
        if ($loader !== null && isset(self::LOADER_TYPES[$loader])) {
            $params['modLoaderType'] = self::LOADER_TYPES[$loader];
        }
        $data = $this->get('/mods/search', $params);

        $hits = [];
        foreach ((array) ($data['data'] ?? []) as $mod) {
            if (!is_array($mod) || !is_int($mod['id'] ?? null)) {
                continue;
            }
            $loaders = [];
            $versions = [];
            foreach ((array) ($mod['latestFilesIndexes'] ?? []) as $index) {
                $key = array_search($index['modLoader'] ?? null, self::LOADER_TYPES, true);
                if ($key !== false && !in_array($key, $loaders, true)) {
                    $loaders[] = $key;
                }
                if (is_string($index['gameVersion'] ?? null) && !in_array($index['gameVersion'], $versions, true)) {
                    $versions[] = $index['gameVersion'];
                }
            }
            usort($versions, 'version_compare');
            $hits[] = [
                'id' => (string) $mod['id'],
                'slug' => (string) ($mod['slug'] ?? ''),
                'title' => (string) ($mod['name'] ?? 'Untitled'),
                'description' => (string) ($mod['summary'] ?? ''),
                'author' => (string) ($mod['authors'][0]['name'] ?? ''),
                'icon' => $this->httpsUrl($mod['logo']['thumbnailUrl'] ?? null),
                'downloads' => (int) ($mod['downloadCount'] ?? 0),
                'loaders' => $loaders,
                'game_versions' => array_slice($versions, -6),
                'url' => $this->httpsUrl($mod['links']['websiteUrl'] ?? null),
            ];
        }

        return ['hits' => $hits, 'total' => min((int) ($data['pagination']['totalCount'] ?? count($hits)), 10000)];
    }

    public function versions(string $projectId): array
    {
        $this->assertId($projectId);
        $data = $this->get('/mods/' . $projectId . '/files', ['pageSize' => 50]);

        $versions = [];
        foreach ((array) ($data['data'] ?? []) as $file) {
            if (!is_array($file) || !is_int($file['id'] ?? null) || !empty($file['isServerPack'])) {
                continue;
            }
            $game = [];
            $loaders = [];
            foreach ((array) ($file['gameVersions'] ?? []) as $tag) {
                if (!is_string($tag)) {
                    continue;
                }
                if (preg_match('/\A\d+\.\d+/', $tag)) {
                    $game[] = $tag;
                } elseif (isset(self::LOADER_TYPES[strtolower($tag)])) {
                    $loaders[] = strtolower($tag);
                }
            }
            $versions[] = [
                'id' => (string) $file['id'],
                'name' => (string) ($file['displayName'] ?? $file['fileName'] ?? $file['id']),
                'version_number' => (string) ($file['fileName'] ?? ''),
                'game_versions' => $game,
                'loaders' => $loaders,
                'type' => match ((int) ($file['releaseType'] ?? 1)) { 2 => 'beta', 3 => 'alpha', default => 'release' },
                'date' => is_string($file['fileDate'] ?? null) ? $file['fileDate'] : null,
                'server_pack' => is_int($file['serverPackFileId'] ?? null),
            ];
        }

        return $versions;
    }

    /**
     * Build the CurseForge-specific half of an install plan.
     */
    public function resolve(string $projectId, string $fileId): array
    {
        $this->assertId($projectId);
        $this->assertId($fileId);
        $project = $this->get('/mods/' . $projectId)['data'] ?? [];
        if ((int) ($project['classId'] ?? 0) !== self::CLASS_MODPACK) {
            throw new ModpackException('That CurseForge project is not a modpack.');
        }
        $file = $this->get('/mods/' . $projectId . '/files/' . $fileId)['data'] ?? [];
        $clientUrl = $this->downloadUrl($file);
        if ($clientUrl === null) {
            throw new ModpackException('The author of this pack has disabled third-party downloads, so it cannot be installed automatically.');
        }

        $manifest = $this->manifest($clientUrl);
        [$loader, $loaderVersion] = $this->primaryLoader($manifest);
        $base = [
            'title' => (string) ($project['name'] ?? 'Modpack'),
            'version_name' => (string) ($file['displayName'] ?? $fileId),
            'minecraft' => (string) ($manifest['minecraft']['version'] ?? ''),
            'loader' => $loader,
            'loader_version' => $loaderVersion,
        ];

        // Prefer the author's server pack: it is curated for dedicated servers.
        if (is_int($file['serverPackFileId'] ?? null)) {
            $serverFile = $this->get('/mods/' . $projectId . '/files/' . $file['serverPackFileId'])['data'] ?? [];
            $serverUrl = $this->downloadUrl($serverFile);
            if ($serverUrl !== null) {
                return $base + [
                    'archive_url' => $serverUrl,
                    'server_pack' => true,
                    'override_dirs' => [],
                    'files' => [],
                    'skipped' => [],
                ];
            }
        }

        [$files, $skipped] = $this->manifestFiles($manifest);
        $overrides = is_string($manifest['overrides'] ?? null) && ModpackPlanner::safePath($manifest['overrides'])
            ? $manifest['overrides'] : 'overrides';

        return $base + [
            'archive_url' => $clientUrl,
            'server_pack' => false,
            'override_dirs' => [$overrides],
            'files' => $files,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{0: array, 1: string[]}
     */
    private function manifestFiles(array $manifest): array
    {
        $wanted = [];
        foreach ((array) ($manifest['files'] ?? []) as $entry) {
            if (is_array($entry) && is_int($entry['fileID'] ?? null) && is_int($entry['projectID'] ?? null)
                && ($entry['required'] ?? true) !== false) {
                $wanted[$entry['fileID']] = $entry['projectID'];
            }
        }
        if (count($wanted) > 2000) {
            throw new ModpackException('This modpack has too many files to install.');
        }
        if ($wanted === []) {
            return [[], []];
        }

        $classes = [];
        foreach (array_chunk(array_values(array_unique($wanted)), 500) as $chunk) {
            foreach ((array) ($this->post('/mods', ['modIds' => $chunk, 'filterPcOnly' => true])['data'] ?? []) as $mod) {
                if (is_array($mod) && is_int($mod['id'] ?? null)) {
                    $classes[$mod['id']] = ['class' => (int) ($mod['classId'] ?? 0), 'name' => (string) ($mod['name'] ?? $mod['id'])];
                }
            }
        }

        $files = [];
        $skipped = [];
        foreach (array_chunk(array_keys($wanted), 500) as $chunk) {
            foreach ((array) ($this->post('/mods/files', ['fileIds' => $chunk])['data'] ?? []) as $file) {
                if (!is_array($file) || !is_int($file['modId'] ?? null)) {
                    continue;
                }
                $mod = $classes[$file['modId']] ?? ['class' => self::CLASS_MOD, 'name' => (string) $file['modId']];
                // Resource packs, shaders, worlds etc. are client-side content.
                if ($mod['class'] !== self::CLASS_MOD) {
                    continue;
                }
                $tags = array_map('strtolower', array_filter((array) ($file['gameVersions'] ?? []), 'is_string'));
                if (in_array('client', $tags, true) && !in_array('server', $tags, true)) {
                    continue;
                }
                $name = (string) ($file['fileName'] ?? '');
                $url = $this->downloadUrl($file);
                if ($url === null || !ModpackPlanner::safePath('mods/' . $name) || str_contains($name, '/')) {
                    $skipped[] = $mod['name'];
                    continue;
                }
                $files[] = ['path' => 'mods/' . $name, 'url' => $url, 'size' => is_int($file['fileLength'] ?? null) ? $file['fileLength'] : null];
            }
        }
        sort($skipped);

        return [$files, $skipped];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function primaryLoader(array $manifest): array
    {
        $loaders = array_values(array_filter((array) ($manifest['minecraft']['modLoaders'] ?? []), 'is_array'));
        $primary = null;
        foreach ($loaders as $loader) {
            if (!empty($loader['primary'])) {
                $primary = $loader;
            }
        }
        $id = (string) (($primary ?? $loaders[0] ?? [])['id'] ?? '');
        if (!preg_match('/\A(forge|neoforge|fabric|quilt)-(.+)\z/i', $id, $m)) {
            throw new ModpackException('This modpack needs a loader this panel cannot install (supported: Fabric, Quilt, Forge, NeoForge).');
        }
        $loader = strtolower($m[1]);
        $version = $m[2];
        // Old NeoForge (1.20.1) ids carry the Minecraft version: neoforge-1.20.1-47.1.99.
        $minecraft = (string) ($manifest['minecraft']['version'] ?? '');
        if (str_starts_with($version, $minecraft . '-')) {
            $version = substr($version, strlen($minecraft) + 1);
        }

        return [$loader, $version];
    }

    private function manifest(string $url): array
    {
        $path = tempnam(sys_get_temp_dir(), 'cfpack');
        try {
            $this->download($url, $path);
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
                throw new ModpackException('The modpack archive is not a valid zip file.');
            }
            try {
                $stat = $zip->statName('manifest.json');
                if ($stat === false || $stat['size'] > 16777216) {
                    throw new ModpackException('The modpack archive has no usable manifest.json.');
                }
                $json = $zip->getFromName('manifest.json');
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }

        try {
            $manifest = json_decode((string) $json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ModpackException('The modpack manifest is not valid JSON.', 0, $exception);
        }
        if (!is_array($manifest) || ($manifest['manifestType'] ?? null) !== 'minecraftModpack') {
            throw new ModpackException('That file is not a CurseForge Minecraft modpack.');
        }

        return $manifest;
    }

    private function download(string $url, string $path): void
    {
        try {
            $response = Http::withHeaders(['User-Agent' => $this->userAgent()])
                ->withOptions([
                    'sink' => $path,
                    'allow_redirects' => [
                        'max' => 3,
                        'protocols' => ['https'],
                        'on_redirect' => function ($request, $response, $uri) {
                            if (!in_array($uri->getHost(), self::FILE_HOSTS, true)) {
                                throw new ModpackException('The modpack download redirected somewhere unexpected.');
                            }
                        },
                    ],
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
            throw new ModpackException(sprintf('CurseForge responded with HTTP %d for the archive.', $response->status()));
        }
    }

    private function downloadUrl(mixed $file): ?string
    {
        $url = is_array($file) ? ($file['downloadUrl'] ?? null) : null;
        if (!is_string($url)) {
            return null;
        }
        // CurseForge returns file names with spaces unencoded.
        $url = str_replace(' ', '%20', $url);
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && in_array($parts['host'] ?? null, self::FILE_HOSTS, true)
            && !isset($parts['user']) && !isset($parts['port']) && strlen($url) <= 2048 ? $url : null;
    }

    private function assertId(string $id): void
    {
        if (!preg_match('/\A[0-9]{1,12}\z/', $id)) {
            throw new ModpackException('Invalid CurseForge identifier.');
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

    private function client()
    {
        if (!$this->configured()) {
            throw new ModpackException('CurseForge needs an API key. Get a free one at console.curseforge.com and set CURSEFORGE_API_KEY for the panel.');
        }

        return Http::withHeaders([
            'x-api-key' => trim((string) config('hydrodactyl.modpacks.curseforge_api_key')),
            'User-Agent' => $this->userAgent(),
            'Accept' => 'application/json',
        ])->timeout(15);
    }

    private function get(string $path, array $query = []): array
    {
        $key = 'modpacks:curseforge:' . sha1($path . '?' . http_build_query($query));

        return Cache::remember($key, 300, fn () => $this->handle(fn ($client) => $client->get(self::API . $path, $query)));
    }

    private function post(string $path, array $body): array
    {
        $key = 'modpacks:curseforge:' . sha1($path . json_encode($body));

        return Cache::remember($key, 300, fn () => $this->handle(fn ($client) => $client->post(self::API . $path, $body)));
    }

    private function handle(callable $send): array
    {
        try {
            $response = $send($this->client());
        } catch (ConnectionException $exception) {
            throw new ModpackException('Could not reach CurseForge.', 0, $exception);
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new ModpackException('CurseForge rejected the API key. Check CURSEFORGE_API_KEY.');
        }
        if ($response->status() === 404) {
            throw new ModpackException('That modpack or file was not found on CurseForge.');
        }
        if ($response->failed()) {
            throw new ModpackException(sprintf('CurseForge responded with HTTP %d.', $response->status()));
        }

        return (array) ($response->json() ?? []);
    }
}
