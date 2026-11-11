<?php

namespace Pterodactyl\Console\Commands\Maintenance;

use Pterodactyl\Models\Egg;
use Illuminate\Console\Command;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Services\Eggs\ImageAliases;
use Pterodactyl\Services\Discord\DiscordNotifier;
use Pterodactyl\Services\Eggs\RegistryImageChecker;

/**
 * Checks that every Docker image the panel can hand to the daemon (egg install
 * containers, egg runtime images, and servers' current images) still exists,
 * after applying ImageAliases. Missing ones are posted to the Discord staff
 * channel so they get fixed before an install fails.
 */
class CheckEggImagesCommand extends Command
{
    protected $signature = 'p:images:check {--quiet-ok : Only print problems}';

    protected $description = 'Check that every Docker image used by eggs and servers still exists in its registry.';

    public function handle(RegistryImageChecker $checker): int
    {
        // image => list of places it's used
        $uses = [];
        foreach (Egg::query()->get() as $egg) {
            $install = $egg->copy_script_container;
            if (is_string($install) && $install !== '') {
                $uses[$install][] = "{$egg->name} (install)";
            }
            foreach ((array) $egg->docker_images as $label => $image) {
                if (is_string($image) && $image !== '') {
                    $uses[$image][] = "{$egg->name} ({$label})";
                }
            }
        }
        foreach (Server::query()->select(['name', 'image'])->get() as $server) {
            if (is_string($server->image) && $server->image !== '') {
                $uses[$server->image][] = "server {$server->name}";
            }
        }

        $missing = [];
        $unknown = 0;
        foreach ($uses as $image => $where) {
            $resolved = ImageAliases::resolve($image);
            $exists = $checker->exists($resolved);
            $label = $resolved === $image ? $image : "{$image} → {$resolved}";
            if ($exists === false) {
                $missing[$label] = array_values(array_unique($where));
                $this->error("MISSING  {$label}  (" . implode(', ', array_slice(array_unique($where), 0, 4)) . ')');
            } elseif ($exists === null) {
                $unknown++;
                $this->warn("unknown  {$label}");
            } elseif (!$this->option('quiet-ok')) {
                $this->line("ok       {$label}");
            }
        }

        $this->info(sprintf('%d image(s) checked, %d missing, %d could not be checked.', count($uses), count($missing), $unknown));
        if ($missing === []) {
            return self::SUCCESS;
        }

        Log::warning('Docker images missing from their registry', ['missing' => array_keys($missing)]);
        if (DiscordNotifier::enabled()) {
            $lines = [];
            foreach (array_slice($missing, 0, 10, true) as $label => $where) {
                $lines[] = '`' . str_replace('`', "'", $label) . '`: ' . DiscordNotifier::escape(implode(', ', array_slice($where, 0, 3)), 150);
            }
            DiscordNotifier::send([
                'title' => 'Docker image missing',
                'color' => DiscordNotifier::RED,
                'description' => "These images no longer exist, so installs or starts that use them will fail:\n" . implode("\n", $lines)
                    . "\n\nFix: point the egg at a working image (Admin → Nests → egg), or add a swap to IMAGE_ALIASES.",
            ]);
        }

        return self::FAILURE;
    }
}
