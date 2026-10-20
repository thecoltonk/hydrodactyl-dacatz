<?php

namespace Pterodactyl\Services\MinecraftPlayers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Custom skins uploaded in the panel for offline-mode players.
 *
 * Skins live on the public disk (served at /storage/...) under unguessable
 * names, because the in-game skin mod hands the URL to MineSkin, which must be
 * able to download it. The per-server index sits next to them under an
 * HMAC-derived name so it survives panel image updates (the public disk is a
 * mounted volume) without being enumerable.
 */
class SkinStore
{
    private const DIR = 'minecraft-skins';

    /**
     * @return array<string, array{name: string, url: string, variant: string, updated_at: string}>
     */
    public function all(Server $server): array
    {
        $disk = Storage::disk('public');
        $path = $this->indexPath($server);
        if (!$disk->exists($path)) {
            return [];
        }
        $index = json_decode((string) $disk->get($path), true);
        if (!is_array($index)) {
            return [];
        }

        $skins = [];
        foreach ($index as $key => $entry) {
            if (is_array($entry) && is_string($entry['file'] ?? null) && $disk->exists($entry['file'])) {
                $skins[$key] = [
                    'name' => (string) ($entry['name'] ?? $key),
                    'url' => $this->url($entry['file']),
                    'variant' => ($entry['variant'] ?? 'classic') === 'slim' ? 'slim' : 'classic',
                    'updated_at' => (string) ($entry['updated_at'] ?? ''),
                ];
            }
        }

        return $skins;
    }

    /**
     * Validate, normalize (re-encode) and store a skin PNG. Returns its public URL.
     */
    public function put(Server $server, string $name, string $contents, string $variant): string
    {
        $info = @getimagesizefromstring($contents);
        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] !== 64 || !in_array($info[1], [32, 64], true)) {
            throw new HttpException(422, 'Skins must be 64×64 (or legacy 64×32) PNG files.');
        }
        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            throw new HttpException(422, 'That PNG could not be read.');
        }
        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        $disk = Storage::disk('public');
        $index = $this->rawIndex($server);
        $key = strtolower($name);
        if (isset($index[$key]['file'])) {
            $disk->delete($index[$key]['file']);
        }

        $file = self::DIR . '/' . Str::random(40) . '.png';
        $disk->put($file, $png);
        $index[$key] = [
            'name' => $name,
            'file' => $file,
            'variant' => $variant === 'slim' ? 'slim' : 'classic',
            'updated_at' => now()->toIso8601String(),
        ];
        $disk->put($this->indexPath($server), json_encode($index));

        return $this->url($file);
    }

    public function forget(Server $server, string $name): void
    {
        $disk = Storage::disk('public');
        $index = $this->rawIndex($server);
        $key = strtolower($name);
        if (isset($index[$key]['file'])) {
            $disk->delete($index[$key]['file']);
        }
        unset($index[$key]);
        $disk->put($this->indexPath($server), json_encode($index));
    }

    public function find(Server $server, string $name): ?array
    {
        return $this->all($server)[strtolower($name)] ?? null;
    }

    private function rawIndex(Server $server): array
    {
        $disk = Storage::disk('public');
        $path = $this->indexPath($server);
        $index = $disk->exists($path) ? json_decode((string) $disk->get($path), true) : [];

        return is_array($index) ? $index : [];
    }

    private function indexPath(Server $server): string
    {
        return self::DIR . '/index-' . substr(hash_hmac('sha256', $server->uuid, (string) config('app.key')), 0, 40) . '.json';
    }

    private function url(string $file): string
    {
        return url('storage/' . $file);
    }
}
