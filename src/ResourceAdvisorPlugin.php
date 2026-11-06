<?php

namespace MaitreAntho\ResourceAdvisor;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use MaitreAntho\ResourceAdvisor\Services\Notifier;

class ResourceAdvisorPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

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

    public function getSettingsFormData(): array
    {
        $hour = config('resource-advisor.digest_hour');

        return [
            'window_days' => (int) config('resource-advisor.window_days', 7),
            'increase_threshold' => (int) round((float) config('resource-advisor.increase_threshold', 0.9) * 100),
            'decrease_threshold' => (int) round((float) config('resource-advisor.decrease_threshold', 0.4) * 100),
            'disk_days_warning' => (int) config('resource-advisor.disk_days_warning', 14),
            'suggest_decrease' => (bool) config('resource-advisor.suggest_decrease', true),
            'discord_webhook' => (string) config('resource-advisor.discord_webhook', ''),
            'notify_admins' => (bool) config('resource-advisor.notify_admins', false),
            'notify_oom' => (bool) config('resource-advisor.notify_oom', true),
            'digest_hour' => $hour === null || $hour === '' ? 'off' : (string) (int) $hour,
        ];
    }

    public function getSettingsForm(): array
    {
        $t = fn (string $key) => trans("resource-advisor::strings.settings.$key");

        return [
            Section::make($t('advice'))->columns(2)->schema([
                TextInput::make('window_days')->label($t('window_days'))->helperText($t('window_days_help'))->integer()->minValue(3)->maxValue(30)->suffix($t('days'))->required(),
                TextInput::make('disk_days_warning')->label($t('disk_days_warning'))->helperText($t('disk_days_warning_help'))->integer()->minValue(1)->maxValue(90)->suffix($t('days'))->required(),
                TextInput::make('increase_threshold')->label($t('increase_threshold'))->helperText($t('increase_threshold_help'))->integer()->minValue(50)->maxValue(100)->suffix('%')->required(),
                TextInput::make('decrease_threshold')->label($t('decrease_threshold'))->helperText($t('decrease_threshold_help'))->integer()->minValue(5)->maxValue(80)->suffix('%')->required(),
                Toggle::make('suggest_decrease')->label($t('suggest_decrease'))->helperText($t('suggest_decrease_help'))->columnSpanFull(),
            ]),
            Section::make($t('alerts'))->description($t('alerts_help'))->columns(2)->schema([
                TextInput::make('discord_webhook')->label($t('discord_webhook'))->helperText($t('discord_webhook_help'))->url()->startsWith(['https://'])->password()->revealable()->columnSpanFull(),
                Toggle::make('notify_admins')->label($t('notify_admins'))->helperText($t('notify_admins_help')),
                Toggle::make('notify_oom')->label($t('notify_oom'))->helperText($t('notify_oom_help')),
                Select::make('digest_hour')->label($t('digest_hour'))->helperText($t('digest_hour_help'))->selectablePlaceholder(false)
                    ->options(['off' => $t('off')] + collect(range(0, 23))->mapWithKeys(fn ($h) => [(string) $h => sprintf('%02d:00', $h)])->all()),
                Actions::make([
                    Action::make('test')->label($t('test'))->icon('tabler-send')->button()->color('gray')->action(function () {
                        $error = app(Notifier::class)->test();
                        $notification = Notification::make()->title($error ? trans('resource-advisor::strings.settings.test_failed') : trans('resource-advisor::strings.settings.test_sent'));
                        ($error ? $notification->body($error)->danger() : $notification->success())->send();
                    }),
                ])->verticallyAlignEnd(),
            ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'RESOURCE_ADVISOR_WINDOW_DAYS' => (int) $data['window_days'],
            'RESOURCE_ADVISOR_INCREASE_THRESHOLD' => round((int) $data['increase_threshold'] / 100, 2),
            'RESOURCE_ADVISOR_DECREASE_THRESHOLD' => round((int) $data['decrease_threshold'] / 100, 2),
            'RESOURCE_ADVISOR_DISK_DAYS_WARNING' => (int) $data['disk_days_warning'],
            'RESOURCE_ADVISOR_SUGGEST_DECREASE' => !empty($data['suggest_decrease']) ? 'true' : 'false',
            'RESOURCE_ADVISOR_DISCORD_WEBHOOK' => trim((string) ($data['discord_webhook'] ?? '')),
            'RESOURCE_ADVISOR_NOTIFY_ADMINS' => !empty($data['notify_admins']) ? 'true' : 'false',
            'RESOURCE_ADVISOR_NOTIFY_OOM' => !empty($data['notify_oom']) ? 'true' : 'false',
            'RESOURCE_ADVISOR_DIGEST_HOUR' => ($data['digest_hour'] ?? 'off') === 'off' ? '' : (int) $data['digest_hour'],
        ]);

        Notification::make()->title(trans('resource-advisor::strings.settings.saved'))->success()->send();
    }
}
