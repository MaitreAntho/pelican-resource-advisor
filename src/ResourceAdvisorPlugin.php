<?php

namespace MaitreAntho\ResourceAdvisor;

use Filament\Contracts\Plugin;
use Filament\Panel;

class ResourceAdvisorPlugin implements Plugin
{
    public function getId(): string
    {
        return 'resource-advisor';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "MaitreAntho\\ResourceAdvisor\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}
}
