<?php

namespace Pterodactyl\Services\Marketplace\Sources;

use Illuminate\Support\Arr;
use Pterodactyl\Services\Marketplace\AbstractMarketplaceSource;
use Pterodactyl\Services\Marketplace\MarketplaceException;
use Pterodactyl\Services\Marketplace\MarketplaceProject;
use Pterodactyl\Services\Marketplace\MarketplaceVersion;
use Pterodactyl\Services\Modpacks\CurseForgeModpacks;

/**
 * CurseForge (https://www.curseforge.com) adapter for single mods and Bukkit
 * plugins. Needs the same free API key as the modpack installer. Files whose
 * authors disabled third-party distribution have no download URL; those are
 * listed but refuse to install, and are never fetched another way.
 */
class CurseForgeSource extends AbstractMarketplaceSource
{
    private const API = 'https://api.curseforge.com/v1';

    private const GAME_MINECRAFT = 432;

    /** Content type => CurseForge class id. */
    private const CLASSES = ['mod' => 6, 'plugin' => 5];

    private const LOADER_TYPES = ['forge' => 1, 'fabric' => 4, 'quilt' => 5, 'neoforge' => 6];

    private const RELEASE_TYPES = [1 => 'release', 2 => 'beta', 3 => 'alpha'];

    public function key(): string
    {
        return 'curseforge';
    }

    public function label(): string
    {
        return 'CurseForge';
    }

    public function supports(string $type): bool
    {
        return isset(self::CLASSES[$type]);
    }

    public function search(string $type, array $filters): array
    {
        $limit = min(max((int) ($filters['limit'] ?? 20), 1), 50);
        $offset = max((int) ($filters['offset'] ?? 0), 0);
        // CurseForge refuses index + pageSize beyond 10,000.
        if ($offset + $limit > 10000) {
            return [];
        }

        $query = [
            'gameId' => self::GAME_MINECRAFT,
            'classId' => self::CLASSES[$type],
            'index' => $offset,
            'pageSize' => $limit,
            'sortOrder' => 'desc',
        ];
        $term = trim((string) ($filters['query'] ?? ''));
        if ($term !== '') {
            $query['searchFilter'] = $term;
        } else {
            $query['sortField'] = 2; // popularity
        }
        $loader = $this->loaderType($type, $filters['loader'] ?? null);
        if ($loader !== null) {
            $query['modLoaderType'] = $loader;
        }
        $gameVersion = $this->gameVersion($filters['game_version'] ?? null);
        if ($gameVersion !== null) {
            $query['gameVersion'] = $gameVersion;
        }

        $payload = $this->request('/mods/search', $query);
        $projects = [];
        foreach ((array) Arr::get($payload, 'data', []) as $mod) {
            if (!is_array($mod) || !is_int($mod['id'] ?? null)) {
                continue;
            }
            $slug = (string) ($mod['slug'] ?? $mod['id']);
            $projects[] = new MarketplaceProject(
                source: $this->key(),
                id: (string) $mod['id'],
                title: (string) ($mod['name'] ?? $slug),
                slug: $slug,
                description: (string) ($mod['summary'] ?? ''),
                author: (string) Arr::get($mod, 'authors.0.name', ''),
                icon: $this->httpsUrl(Arr::get($mod, 'logo.thumbnailUrl')),
                downloads: (int) ($mod['downloadCount'] ?? 0),
                categories: array_values(array_filter(array_map(
                    fn ($category) => is_array($category) && is_string($category['name'] ?? null) ? $category['name'] : null,
                    (array) ($mod['categories'] ?? []),
                ))),
                url: $this->httpsUrl(Arr::get($mod, 'links.websiteUrl')),
                updatedAt: is_string($mod['dateModified'] ?? null) ? $mod['dateModified'] : null,
            );
        }

        return $projects;
    }

    public function versions(string $type, string $projectId, array $filters): array
    {
        $query = ['pageSize' => 50, 'index' => 0];
        $loader = $this->loaderType($type, $filters['loader'] ?? null);
        if ($loader !== null) {
            $query['modLoaderType'] = $loader;
        }
        $gameVersion = $this->gameVersion($filters['game_version'] ?? null);
        if ($gameVersion !== null) {
            $query['gameVersion'] = $gameVersion;
        }

        $payload = $this->request('/mods/' . $this->id($projectId) . '/files', $query);
        $versions = [];
        foreach ((array) Arr::get($payload, 'data', []) as $file) {
            if (!is_array($file) || !is_int($file['id'] ?? null) || ($file['isAvailable'] ?? true) === false) {
                continue;
            }
            $tags = array_values(array_filter((array) ($file['gameVersions'] ?? []), 'is_string'));
            $loaders = [];
            $mcVersions = [];
            foreach ($tags as $tag) {
                $lower = strtolower($tag);
                if (isset(self::LOADER_TYPES[$lower])) {
                    $loaders[] = $lower;
                } elseif (preg_match('/^\d+\.\d+(\.\d+)?(-[a-z0-9.]+)?$/i', $tag)) {
                    $mcVersions[] = $tag;
                }
            }
            $name = (string) ($file['displayName'] ?? $file['fileName'] ?? $file['id']);
            if (!is_string($file['downloadUrl'] ?? null)) {
                $name .= ' (CurseForge download only)';
            }
            $versions[] = new MarketplaceVersion(
                id: (string) $file['id'],
                name: $name,
                loaders: $loaders,
                gameVersions: $mcVersions,
                size: is_numeric($file['fileLength'] ?? null) ? (int) $file['fileLength'] : null,
                date: is_string($file['fileDate'] ?? null) ? $file['fileDate'] : null,
                type: self::RELEASE_TYPES[(int) ($file['releaseType'] ?? 1)] ?? 'release',
            );
        }

        return $versions;
    }

    public function resolve(string $type, string $projectId, string $versionId): array
    {
        $payload = $this->request('/mods/' . $this->id($projectId) . '/files/' . $this->id($versionId), [], true);
        $file = (array) Arr::get($payload, 'data', []);
        $filename = basename((string) ($file['fileName'] ?? ''));
        $url = $file['downloadUrl'] ?? null;
        if (!is_string($url) || $url === '') {
            throw MarketplaceException::user(
                $this->key(),
                'The author of this file turned off downloads outside the CurseForge app, so it has to be downloaded manually from CurseForge.',
            );
        }
        if ($filename === '' || !preg_match('/\.(jar|zip)$/i', $filename)) {
            throw MarketplaceException::user($this->key(), 'This CurseForge file is not a jar and cannot be installed here.');
        }

        // CurseForge returns paths with unencoded spaces, and its edge CDN
        // redirects. The daemon may not follow redirects, so hand it the
        // final URL (still restricted to CurseForge's CDN hosts).
        $url = CurseForgeModpacks::directCdnUrl(str_replace(' ', '%20', $url));
        $this->assertSafeDownloadUrl($url, CurseForgeModpacks::FILE_HOSTS);
        $url = $this->followRedirect($url);
        $this->assertSafeDownloadUrl($url, CurseForgeModpacks::FILE_HOSTS);

        return [
            'url' => $url,
            'filename' => $filename,
            'size' => is_numeric($file['fileLength'] ?? null) ? (int) $file['fileLength'] : null,
        ];
    }

    private function request(string $path, array $query, bool $shortLived = false): array
    {
        $key = trim((string) config('hydrodactyl.modpacks.curseforge_api_key'));
        if ($key === '') {
            throw MarketplaceException::user($this->key(), 'CurseForge needs an API key. Set CURSEFORGE_API_KEY for the panel.');
        }

        try {
            return $shortLived
                ? $this->getShortLived(self::API . $path, $query, ['x-api-key' => $key])
                : $this->get(self::API . $path, $query, ['x-api-key' => $key]);
        } catch (MarketplaceException $exception) {
            if (str_contains($exception->getMessage(), 'HTTP 401') || str_contains($exception->getMessage(), 'HTTP 403')) {
                throw MarketplaceException::user($this->key(), CurseForgeModpacks::keyProblem(), $exception);
            }
            if (str_contains($exception->getMessage(), 'HTTP 404')) {
                throw MarketplaceException::user($this->key(), 'That project or file was not found on CurseForge.', $exception);
            }
            throw $exception;
        }
    }

    private function loaderType(string $type, mixed $loader): ?int
    {
        if ($type !== 'mod' || !is_string($loader)) {
            return null;
        }

        return self::LOADER_TYPES[$this->normalizeLoader($loader)] ?? null;
    }

    private function gameVersion(mixed $version): ?string
    {
        return is_string($version) && preg_match('/^[0-9A-Za-z.\-]{1,20}$/', $version) ? $version : null;
    }

    private function id(string $value): string
    {
        if (!ctype_digit($value) || strlen($value) > 12) {
            throw MarketplaceException::user($this->key(), 'Invalid CurseForge id.');
        }

        return $value;
    }

    private function httpsUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && strlen($url) < 1024 ? $url : null;
    }
}
