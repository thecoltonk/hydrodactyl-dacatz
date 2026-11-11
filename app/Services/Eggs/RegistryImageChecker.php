<?php

namespace Pterodactyl\Services\Eggs;

use Illuminate\Support\Facades\Http;

/**
 * Asks a container registry whether an image tag exists, using the same
 * anonymous token flow `docker pull` does. HEAD requests don't count toward
 * Docker Hub's pull limits.
 */
class RegistryImageChecker
{
    private const ACCEPT = 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, '
        . 'application/vnd.docker.distribution.manifest.v2+json, application/vnd.oci.image.manifest.v1+json';

    /**
     * true = exists, false = missing (or private), null = couldn't tell.
     */
    public function exists(string $image): ?bool
    {
        $reference = $this->parse($image);
        if ($reference === null) {
            return null;
        }
        [$host, $repository, $tag] = $reference;
        $url = "https://{$host}/v2/{$repository}/manifests/{$tag}";

        try {
            $response = Http::timeout(15)->withHeaders(['Accept' => self::ACCEPT])->head($url);
            if ($response->status() === 401) {
                $token = $this->token((string) $response->header('WWW-Authenticate'));
                if ($token === null) {
                    return null;
                }
                $response = Http::timeout(15)->withToken($token)->withHeaders(['Accept' => self::ACCEPT])->head($url);
            }
        } catch (\Throwable) {
            return null;
        }

        return match (true) {
            $response->successful() => true,
            // 404: tag gone. 401 even with a token: the repository doesn't exist (or is private).
            in_array($response->status(), [401, 404], true) => false,
            default => null,
        };
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null host, repository, tag
     */
    private function parse(string $image): ?array
    {
        $image = trim($image);
        if ($image === '' || str_contains($image, '@')) {
            return null; // pinned digests aren't worth checking
        }
        $parts = explode('/', $image);
        $host = 'registry-1.docker.io';
        if (count($parts) > 1 && (str_contains($parts[0], '.') || str_contains($parts[0], ':') || $parts[0] === 'localhost')) {
            $host = array_shift($parts);
            if ($host === 'docker.io') {
                $host = 'registry-1.docker.io';
            }
        }
        $path = implode('/', $parts);
        $tag = 'latest';
        $last = strrpos($path, ':');
        if ($last !== false && $last > (int) strrpos($path, '/')) {
            $tag = substr($path, $last + 1);
            $path = substr($path, 0, $last);
        }
        if ($host === 'registry-1.docker.io' && !str_contains($path, '/')) {
            $path = 'library/' . $path;
        }
        if (!preg_match('~\A[a-z0-9._/-]+\z~', $path) || !preg_match('~\A[\w.-]{1,128}\z~', $tag)
            || !preg_match('~\A[a-z0-9.-]+(:\d+)?\z~i', $host)) {
            return null;
        }

        return [$host, $path, $tag];
    }

    private function token(string $challenge): ?string
    {
        if (!preg_match('~^Bearer\s+(.+)$~i', trim($challenge), $match)) {
            return null;
        }
        preg_match_all('~(\w+)="([^"]*)"~', $match[1], $pairs, PREG_SET_ORDER);
        $params = [];
        foreach ($pairs as [, $key, $value]) {
            $params[strtolower($key)] = $value;
        }
        $realm = $params['realm'] ?? null;
        if (!is_string($realm) || !str_starts_with($realm, 'https://')) {
            return null;
        }
        unset($params['realm']);

        try {
            $response = Http::timeout(15)->get($realm, $params);
        } catch (\Throwable) {
            return null;
        }
        $token = $response->json('token') ?? $response->json('access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
