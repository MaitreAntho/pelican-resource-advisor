<?php

namespace MaitreAntho\ResourceAdvisor\Providers;

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use MaitreAntho\ResourceAdvisor\Console\Commands\CollectCommand;
use MaitreAntho\ResourceAdvisor\Console\Commands\PruneCommand;

class ResourceAdvisorPluginProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Schedule::command(CollectCommand::class)->everyFiveMinutes()->withoutOverlapping(10);
        Schedule::command(PruneCommand::class)->daily();
    }
}
