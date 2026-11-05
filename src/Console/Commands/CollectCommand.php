<?php

namespace MaitreAntho\ResourceAdvisor\Console\Commands;

use Illuminate\Console\Command;
use MaitreAntho\ResourceAdvisor\Services\Collector;

class CollectCommand extends Command
{
    protected $signature = 'p:resource-advisor:collect';

    protected $description = 'Records the current memory, disk and CPU use of every server.';

    public function handle(Collector $collector): int
    {
        $result = $collector->collect();

        $this->info("Recorded {$result['servers']} servers on {$result['nodes']} nodes" . ($result['failed'] ? ", {$result['failed']} nodes unreachable" : '') . '.');

        return self::SUCCESS;
    }
}
