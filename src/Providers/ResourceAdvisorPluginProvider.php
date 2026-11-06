<?php

namespace MaitreAntho\ResourceAdvisor\Providers;

use App\Models\Role;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use MaitreAntho\ResourceAdvisor\Console\Commands\CollectCommand;
use MaitreAntho\ResourceAdvisor\Console\Commands\NotifyCommand;
use MaitreAntho\ResourceAdvisor\Console\Commands\PruneCommand;

class ResourceAdvisorPluginProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // "view resourceAdvisor" opens the page, "update resourceAdvisor" applies limits and ignores servers.
        Role::registerCustomPermissions(['resourceAdvisor' => ['view', 'update']]);
        Role::registerCustomModelIcon('resourceAdvisor', 'tabler-gauge');

        Schedule::command(CollectCommand::class)->everyFiveMinutes()->withoutOverlapping(10);
        Schedule::command(NotifyCommand::class)->everyFiveMinutes()->withoutOverlapping(10);
        Schedule::command(PruneCommand::class)->daily();
    }
}
