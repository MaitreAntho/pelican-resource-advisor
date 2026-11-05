<?php

namespace MaitreAntho\ResourceAdvisor\Services;

use App\Models\ActivityLog;
use App\Models\Server;
use Illuminate\Support\Collection;
use MaitreAntho\ResourceAdvisor\Models\ResourceSample;

/**
 * Turns the samples of a server into a recommendation for its memory, disk and CPU limits.
 */
class Advisor
{
    public const INCREASE = 'increase';

    public const DECREASE = 'decrease';

    public const OK = 'ok';

    public const COLLECTING = 'collecting';

    public const UNLIMITED = 'unlimited';

    private const MIB = 1048576;

    /**
     * @param  Collection<int, Server>  $servers
     * @return array<int, array<string, mixed>> keyed by server id
     */
    public function analyse(Collection $servers): array
    {
        $days = (int) config('resource-advisor.window_days', 7);
        $since = now()->subDays($days);
        $ids = $servers->pluck('id')->all();

        $samples = ResourceSample::query()
            ->whereIn('server_id', $ids)
            ->where('hour', '>=', $since->copy()->startOfHour())
            ->orderBy('hour')
            ->get()
            ->groupBy('server_id');

        $oomKills = $this->oomKills($ids, $since);

        $out = [];
        foreach ($servers as $server) {
            $out[$server->id] = $this->analyseServer($server, $samples->get($server->id, collect()), $oomKills[$server->id] ?? 0);
        }

        return $out;
    }

    /**
     * @param  Collection<int, ResourceSample>  $rows
     * @return array<string, mixed>
     */
    public function analyseServer(Server $server, Collection $rows, int $oomKills = 0): array
    {
        $memory = $this->memory($server, $rows, $oomKills);
        $disk = $this->disk($server, $rows);
        $cpu = $this->cpu($server, $rows);

        $order = [self::INCREASE => 4, self::DECREASE => 3, self::OK => 2, self::COLLECTING => 1, self::UNLIMITED => 0];
        $status = collect([$memory['status'], $disk['status'], $cpu['status']])->sortByDesc(fn ($s) => $order[$s])->first();
        if ($status === self::UNLIMITED) {
            $status = self::OK;
        }

        return [
            'status' => $status,
            'memory' => $memory,
            'disk' => $disk,
            'cpu' => $cpu,
            'hours' => $rows->count(),
            'java' => $this->isJava($server),
        ];
    }

    /**
     * True for servers running on the JVM (Minecraft, Project Zomboid...): Java keeps the memory
     * it was given, so a full memory graph does not mean the server needs more.
     */
    public function isJava(Server $server): bool
    {
        $text = strtolower($server->startup . ' ' . $server->image);

        foreach (['java', '-xmx', '-xms', '.jar', 'jre', 'jdk', 'temurin', 'graalvm'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param Collection<int, ResourceSample> $rows */
    private function memory(Server $server, Collection $rows, int $oomKills): array
    {
        $limit = (int) $server->memory * self::MIB;
        $running = $rows->where('running_samples', '>', 0);
        $peak = $this->percentile($running->pluck('memory_max')->all(), 0.95);
        $max = (int) $running->max('memory_max');
        $average = $running->sum('running_samples') > 0 ? (int) ($running->sum('memory_sum') / $running->sum('running_samples')) : 0;

        $base = ['limit' => $limit, 'peak' => $peak, 'max' => $max, 'average' => $average, 'oom_kills' => $oomKills, 'suggested' => null, 'reason' => null];

        if ($limit <= 0) {
            return ['status' => self::UNLIMITED] + $base;
        }

        if ($oomKills > 0) {
            return ['status' => self::INCREASE, 'reason' => 'oom', 'suggested' => $this->roundUp(max($limit * 1.5, $max * 1.3), 512 * self::MIB)] + $base;
        }

        if ($running->count() < (int) config('resource-advisor.min_running_hours', 12)) {
            return ['status' => self::COLLECTING] + $base;
        }

        $java = $this->isJava($server);
        $ratio = $peak / $limit;

        if (!$java && $ratio >= (float) config('resource-advisor.increase_threshold', 0.9)) {
            return ['status' => self::INCREASE, 'reason' => 'peak', 'suggested' => $this->roundUp(max($peak * 1.3, $limit * 1.25), 512 * self::MIB)] + $base;
        }

        if (!$java && $this->suggestDecrease($rows) && $max / $limit < (float) config('resource-advisor.decrease_threshold', 0.4)) {
            $suggested = $this->roundUp(max($max * 1.5, 512 * self::MIB), 512 * self::MIB);
            if ($suggested <= $limit * 0.75) {
                return ['status' => self::DECREASE, 'reason' => 'low', 'suggested' => $suggested] + $base;
            }
        }

        return ['status' => self::OK, 'reason' => $java ? 'java' : null] + $base;
    }

    /** @param Collection<int, ResourceSample> $rows */
    private function disk(Server $server, Collection $rows): array
    {
        $limit = (int) $server->disk * self::MIB;
        $usage = (int) ($rows->last()?->disk_last ?? 0);
        $growth = $this->dailyGrowth($rows);
        $daysLeft = ($limit > 0 && $growth > 0) ? max(0.0, ($limit - $usage) / $growth) : null;

        $base = ['limit' => $limit, 'usage' => $usage, 'growth_per_day' => $growth, 'days_left' => $daysLeft, 'suggested' => null, 'reason' => null];

        if ($limit <= 0) {
            return ['status' => self::UNLIMITED] + $base;
        }

        if ($rows->isEmpty()) {
            return ['status' => self::COLLECTING] + $base;
        }

        // A disk already nearly full needs more space now, whatever the history.
        $full = $usage / $limit >= (float) config('resource-advisor.increase_threshold', 0.9);
        $enoughHistory = $rows->count() >= (int) config('resource-advisor.min_hours', 24);
        $soonFull = $enoughHistory && $daysLeft !== null && $daysLeft < (float) config('resource-advisor.disk_days_warning', 14);

        if ($full || $soonFull) {
            $target = max($usage * 1.25, $usage + max(0, $growth) * 30);

            return ['status' => self::INCREASE, 'reason' => $full ? 'full' : 'growth', 'suggested' => $this->roundUp(max($target, $limit + 1024 * self::MIB), 1024 * self::MIB)] + $base;
        }

        if (!$enoughHistory) {
            return ['status' => self::COLLECTING] + $base;
        }

        // Disks fill up with backups, logs and updates: keep three times the usage and at least 2 GiB,
        // and only suggest it when it frees at least half of the space.
        if ($this->suggestDecrease($rows) && $growth <= 0 && $usage / $limit < 0.25) {
            $suggested = $this->roundUp(max($usage * 3, 2048 * self::MIB), 1024 * self::MIB);
            if ($suggested <= $limit / 2) {
                return ['status' => self::DECREASE, 'reason' => 'low', 'suggested' => $suggested] + $base;
            }
        }

        return ['status' => self::OK] + $base;
    }

    /** @param Collection<int, ResourceSample> $rows */
    private function cpu(Server $server, Collection $rows): array
    {
        $limit = (float) $server->cpu;
        $running = $rows->where('running_samples', '>', 0);
        $averages = $running->map(fn (ResourceSample $row) => $row->cpu_sum / max(1, $row->running_samples))->all();
        $busy = $this->percentile($averages, 0.95);
        $peak = $this->percentile($running->pluck('cpu_max')->all(), 0.95);
        $typical = $this->percentile($averages, 0.5);

        $base = ['limit' => $limit, 'busy' => round($busy, 1), 'peak' => round($peak, 1), 'suggested' => null, 'reason' => null];

        if ($limit <= 0) {
            return ['status' => self::UNLIMITED] + $base;
        }

        if ($running->count() < (int) config('resource-advisor.min_running_hours', 12)) {
            return ['status' => self::COLLECTING] + $base;
        }

        // Busy at its peak hours, or at the limit while staying busy most of the time.
        if ($busy >= $limit * 0.85 || ($peak >= $limit * 0.95 && $typical >= $limit * 0.7)) {
            return ['status' => self::INCREASE, 'reason' => 'busy', 'suggested' => $this->roundUp(max($peak * 1.3, $limit * 1.5), 50)] + $base;
        }

        if ($this->suggestDecrease($rows) && $limit > 100 && $peak < $limit * 0.3) {
            $suggested = $this->roundUp(max($peak * 1.5, 100), 50);
            if ($suggested < $limit) {
                return ['status' => self::DECREASE, 'reason' => 'low', 'suggested' => $suggested] + $base;
            }
        }

        return ['status' => self::OK] + $base;
    }

    /** Lower limits are only suggested with a full window of data, so a quiet first day is not mistaken for a quiet server. */
    private function suggestDecrease(Collection $rows): bool
    {
        if (!config('resource-advisor.suggest_decrease', true) || $rows->isEmpty()) {
            return false;
        }

        $days = (int) config('resource-advisor.window_days', 7);

        return $rows->first()->hour->lte(now()->subDays($days)->addHours(6));
    }

    /**
     * Disk growth in bytes per day, from a least squares fit of the hourly maxima.
     *
     * @param  Collection<int, ResourceSample>  $rows
     */
    private function dailyGrowth(Collection $rows): float
    {
        if ($rows->count() < 2) {
            return 0.0;
        }

        $start = $rows->first()->hour->getTimestamp();
        $xs = $rows->map(fn (ResourceSample $row) => ($row->hour->getTimestamp() - $start) / 86400)->values()->all();
        $ys = $rows->pluck('disk_max')->map(fn ($v) => (float) $v)->values()->all();

        $n = count($xs);
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $num = 0.0;
        $den = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $num += ($xs[$i] - $mx) * ($ys[$i] - $my);
            $den += ($xs[$i] - $mx) ** 2;
        }

        return $den > 0 ? $num / $den : 0.0;
    }

    /** @param array<int, int|float> $values */
    private function percentile(array $values, float $p): float
    {
        if (!$values) {
            return 0.0;
        }

        sort($values);
        $index = (int) ceil($p * count($values)) - 1;

        return (float) $values[max(0, min($index, count($values) - 1))];
    }

    private function roundUp(float $value, int $step): int
    {
        return (int) (ceil($value / $step) * $step);
    }

    /**
     * Crashes caused by the out of memory killer, as reported by Wings, per server id.
     *
     * @param  array<int, int>  $serverIds
     * @return array<int, int>
     */
    private function oomKills(array $serverIds, \Illuminate\Support\Carbon $since): array
    {
        if (!$serverIds) {
            return [];
        }

        $morph = (new Server())->getMorphClass();
        $counts = [];

        ActivityLog::query()
            ->where('event', 'server:crashed')
            ->where('timestamp', '>=', $since)
            ->whereHas('subjects', fn ($query) => $query->where('subject_type', $morph)->whereIn('subject_id', $serverIds))
            ->get()
            ->each(function (ActivityLog $log) use (&$counts, $morph) {
                $oom = $log->properties->get('oomkilled');
                if (!filter_var($oom, FILTER_VALIDATE_BOOLEAN)) {
                    return;
                }

                foreach ($log->subjects as $subject) {
                    if ($subject->subject_type === $morph) {
                        $counts[$subject->subject_id] = ($counts[$subject->subject_id] ?? 0) + 1;
                    }
                }
            });

        return $counts;
    }
}
