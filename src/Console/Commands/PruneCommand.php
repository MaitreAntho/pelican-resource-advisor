<?php

namespace MaitreAntho\ResourceAdvisor\Console\Commands;

use Illuminate\Console\Command;
use MaitreAntho\ResourceAdvisor\Models\ResourceSample;

class PruneCommand extends Command
{
    protected $signature = 'p:resource-advisor:prune';

    protected $description = 'Deletes usage samples older than the retention period.';

    public function handle(): int
    {
        $deleted = ResourceSample::query()
            ->where('hour', '<', now()->subDays((int) config('resource-advisor.retention_days', 35)))
            ->delete();

        $this->info("Deleted $deleted old samples.");

        return self::SUCCESS;
    }
}
