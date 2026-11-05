<?php

namespace MaitreAntho\ResourceAdvisor\Services;

use App\Models\Node;
use App\Models\Server;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MaitreAntho\ResourceAdvisor\Models\ResourceSample;

class Collector
{
    /**
     * Records one sample for every server, with a single request per node.
     *
     * @return array{nodes: int, servers: int, failed: int}
     */
    public function collect(?Carbon $at = null): array
    {
        $hour = ($at ?? now())->copy()->startOfHour();
        $result = ['nodes' => 0, 'servers' => 0, 'failed' => 0];

        foreach (Node::query()->get() as $node) {
            if ($node->isUnderMaintenance()) {
                continue;
            }

            $result['nodes']++;

            try {
                $entries = Http::daemon($node)->timeout(10)->connectTimeout(5)->get('/api/servers')->throw()->json();
            } catch (Exception $exception) {
                report($exception);
                $result['failed']++;

                continue;
            }

            if (!is_array($entries)) {
                $result['failed']++;

                continue;
            }

            $servers = $node->servers()->get(['id', 'uuid'])->keyBy('uuid');

            foreach ($entries as $entry) {
                $uuid = $entry['configuration']['uuid'] ?? null;
                $server = $uuid ? $servers->get($uuid) : null;
                if (!$server instanceof Server) {
                    continue;
                }

                $this->record($server->id, $hour, $entry['state'] ?? 'offline', $entry['utilization'] ?? []);
                $result['servers']++;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $utilization */
    public function record(int $serverId, Carbon $hour, string $state, array $utilization): void
    {
        $running = in_array($state, ['running', 'starting'], true);
        $memory = max(0, (int) ($utilization['memory_bytes'] ?? 0));
        $cpu = max(0.0, (float) ($utilization['cpu_absolute'] ?? 0));
        $disk = max(0, (int) ($utilization['disk_bytes'] ?? 0));

        $sample = ResourceSample::query()->firstOrNew(['server_id' => $serverId, 'hour' => $hour]);

        $sample->samples = (int) $sample->samples + 1;
        $sample->disk_max = max((int) $sample->disk_max, $disk);
        $sample->disk_last = $disk;

        if ($running) {
            $sample->running_samples = (int) $sample->running_samples + 1;
            $sample->memory_max = max((int) $sample->memory_max, $memory);
            $sample->memory_sum = (int) $sample->memory_sum + $memory;
            $sample->cpu_max = max((float) $sample->cpu_max, $cpu);
            $sample->cpu_sum = (float) $sample->cpu_sum + $cpu;
        }

        $sample->save();
    }
}
