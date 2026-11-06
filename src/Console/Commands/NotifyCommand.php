<?php

namespace MaitreAntho\ResourceAdvisor\Console\Commands;

use Illuminate\Console\Command;
use MaitreAntho\ResourceAdvisor\Services\Notifier;

class NotifyCommand extends Command
{
    protected $signature = 'p:resource-advisor:notify {--digest : Send the daily summary now} {--test : Send a test message}';

    protected $description = 'Sends the out of memory alerts and the daily summary of Resource Advisor.';

    public function handle(Notifier $notifier): int
    {
        if ($this->option('test')) {
            $error = $notifier->test();
            $error ? $this->error($error) : $this->info('Test message sent.');

            return $error ? self::FAILURE : self::SUCCESS;
        }

        $sent = $notifier->oomKills();
        $digest = $notifier->digest((bool) $this->option('digest'));

        $this->info("Sent $sent out of memory alerts" . ($digest ? ' and the daily summary' : '') . '.');

        return self::SUCCESS;
    }
}
