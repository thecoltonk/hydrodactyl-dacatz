<?php

namespace Pterodactyl\Services\MinecraftPlayers;

/**
 * Summarizes Minecraft's per-player statistics file (world/stats/<uuid>.json).
 */
class StatsSnapshot
{
    private const TOP = 12;

    public function summarize(array $stats): array
    {
        $custom = $this->counts($stats['minecraft:custom'] ?? []);
        $value = fn (string $key) => $custom['minecraft:' . $key] ?? 0;

        // play_time replaced play_one_minute (both count ticks).
        $ticks = $value('play_time') ?: $value('play_one_minute');
        $playerKills = $value('player_kills');
        $mobKills = $value('mob_kills');
        $deaths = $value('deaths');

        $distance = [];
        foreach ($custom as $key => $cm) {
            if (str_ends_with($key, '_one_cm')) {
                $distance[substr($key, 10, -7)] = $cm;
            }
        }
        arsort($distance);
        $travelled = array_sum(array_diff_key($distance, ['fall' => 0]));

        return [
            'play_time_seconds' => intdiv($ticks, 20),
            'player_kills' => $playerKills,
            'mob_kills' => $mobKills,
            'deaths' => $deaths,
            'kdr' => $deaths > 0 ? round($playerKills / $deaths, 2) : (float) $playerKills,
            'mob_kdr' => $deaths > 0 ? round($mobKills / $deaths, 2) : (float) $mobKills,
            'damage_dealt' => intdiv($value('damage_dealt'), 10),
            'damage_taken' => intdiv($value('damage_taken'), 10),
            'jumps' => $value('jump'),
            'time_since_death_seconds' => intdiv($value('time_since_death'), 20),
            'distance_cm' => $travelled,
            'distance' => $this->top($distance, 20),
            'blocks_mined' => $this->summary($stats['minecraft:mined'] ?? []),
            'items_used' => $this->summary($stats['minecraft:used'] ?? []),
            'items_crafted' => $this->summary($stats['minecraft:crafted'] ?? []),
            'mobs_killed' => $this->summary($stats['minecraft:killed'] ?? []),
            'killed_by' => $this->summary($stats['minecraft:killed_by'] ?? []),
        ];
    }

    private function summary(mixed $group): array
    {
        $counts = $this->counts($group);
        arsort($counts);

        return ['total' => array_sum($counts), 'top' => $this->top($counts, self::TOP)];
    }

    private function top(array $counts, int $limit): array
    {
        $out = [];
        foreach (array_slice($counts, 0, $limit, true) as $id => $count) {
            $out[] = ['id' => (string) $id, 'count' => $count];
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function counts(mixed $group): array
    {
        $counts = [];
        if (!is_array($group)) {
            return $counts;
        }
        foreach (array_slice($group, 0, 5000, true) as $id => $count) {
            if (is_string($id) && preg_match('~\A[a-z0-9_.-]+:[a-z0-9_./-]+\z~', $id) && is_int($count) && $count > 0) {
                $counts[$id] = $count;
            }
        }

        return $counts;
    }
}
