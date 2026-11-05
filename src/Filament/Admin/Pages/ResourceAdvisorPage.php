<?php

namespace MaitreAntho\ResourceAdvisor\Filament\Admin\Pages;

use App\Enums\CustomizationKey;
use App\Models\Server;
use App\Services\Servers\BuildModificationService;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use MaitreAntho\ResourceAdvisor\Models\ResourceSample;
use MaitreAntho\ResourceAdvisor\Services\Advisor;

class ResourceAdvisorPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-gauge';

    protected static ?string $slug = 'resource-advisor';

    protected static ?int $navigationSort = 5;

    protected string $view = 'resource-advisor::page';

    /** @var array<int, array<string, mixed>> */
    private array $results = [];

    private ?array $allResults = null;

    private const MIB = 1048576;

    public static function canAccess(): bool
    {
        return user()?->can('viewList', Server::class) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return trans('resource-advisor::strings.title');
    }

    public static function getNavigationGroup(): ?string
    {
        if (user()?->getCustomization(CustomizationKey::TopNavigation) === 'topbar') {
            return null;
        }

        return trans('admin/dashboard.server');
    }

    public function getTitle(): string
    {
        return trans('resource-advisor::strings.title');
    }

    public function getSubheading(): ?string
    {
        $counts = collect($this->allResults())->countBy('status');

        return trans('resource-advisor::strings.summary', [
            'increase' => $counts->get(Advisor::INCREASE, 0),
            'decrease' => $counts->get(Advisor::DECREASE, 0),
            'collecting' => $counts->get(Advisor::COLLECTING, 0),
            'days' => (int) config('resource-advisor.window_days', 7),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Server::query()->with(['node', 'user']))
            ->defaultSort('name')
            ->deferFilters(false)
            ->columns([
                TextColumn::make('name')
                    ->label(trans('resource-advisor::strings.server'))
                    ->description(fn (Server $server) => $server->node->name . ' · ' . $server->user->username)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('advice_status')
                    ->label(trans('resource-advisor::strings.status'))
                    ->state(fn (Server $server) => $this->result($server)['status'])
                    ->formatStateUsing(fn (string $state) => trans("resource-advisor::strings.statuses.$state"))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        Advisor::INCREASE => 'danger',
                        Advisor::DECREASE => 'warning',
                        Advisor::OK => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('advice_memory')
                    ->label(trans('resource-advisor::strings.memory'))
                    ->state(fn (Server $server) => $this->describeMemory($this->result($server)['memory']))
                    ->description(fn (Server $server) => $this->advice('memory', $this->result($server)['memory'], fn ($v) => $this->bytes($v)))
                    ->color(fn (Server $server) => $this->color($this->result($server)['memory']['status']))
                    ->wrap(),
                TextColumn::make('advice_disk')
                    ->label(trans('resource-advisor::strings.disk'))
                    ->state(fn (Server $server) => $this->describeDisk($this->result($server)['disk']))
                    ->description(fn (Server $server) => $this->advice('disk', $this->result($server)['disk'], fn ($v) => $this->bytes($v)))
                    ->color(fn (Server $server) => $this->color($this->result($server)['disk']['status']))
                    ->wrap(),
                TextColumn::make('advice_cpu')
                    ->label(trans('resource-advisor::strings.cpu'))
                    ->state(fn (Server $server) => $this->describeCpu($this->result($server)['cpu']))
                    ->description(fn (Server $server) => $this->advice('cpu', $this->result($server)['cpu'], fn ($v) => $v . ' %'))
                    ->color(fn (Server $server) => $this->color($this->result($server)['cpu']['status']))
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('advice')
                    ->label(trans('resource-advisor::strings.status'))
                    ->options([
                        'attention' => trans('resource-advisor::strings.filters.attention'),
                        Advisor::INCREASE => trans('resource-advisor::strings.statuses.increase'),
                        Advisor::DECREASE => trans('resource-advisor::strings.statuses.decrease'),
                        Advisor::OK => trans('resource-advisor::strings.statuses.ok'),
                        Advisor::COLLECTING => trans('resource-advisor::strings.statuses.collecting'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'] ?? null;
                        if (!$value) {
                            return $query;
                        }

                        $wanted = $value === 'attention' ? [Advisor::INCREASE, Advisor::DECREASE] : [$value];
                        $ids = collect($this->allResults())->filter(fn ($result) => in_array($result['status'], $wanted, true))->keys()->all();

                        return $query->whereIn('id', $ids);
                    }),
            ])
            ->recordActions([
                Action::make('usage')
                    ->label(trans('resource-advisor::strings.usage'))
                    ->tooltip(trans('resource-advisor::strings.usage'))
                    ->icon('tabler-chart-line')
                    ->modalHeading(fn (Server $server) => trans('resource-advisor::strings.usage_of', ['server' => $server->name]))
                    ->modalContent(fn (Server $server) => view('resource-advisor::usage', [
                        'server' => $server,
                        'result' => $this->result($server),
                        'rows' => ResourceSample::query()->where('server_id', $server->id)->where('hour', '>=', now()->subDays((int) config('resource-advisor.window_days', 7))->startOfHour())->orderBy('hour')->get(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(trans('resource-advisor::strings.close'))
                    ->modalWidth('5xl'),
                Action::make('apply')
                    ->label(trans('resource-advisor::strings.apply'))
                    ->tooltip(trans('resource-advisor::strings.apply'))
                    ->icon('tabler-adjustments-up')
                    ->color('primary')
                    ->visible(fn (Server $server) => user()?->can('update', $server) && $this->hasSuggestion($this->result($server)))
                    ->modalHeading(fn (Server $server) => trans('resource-advisor::strings.apply_to', ['server' => $server->name]))
                    ->modalDescription(trans('resource-advisor::strings.apply_description'))
                    ->fillForm(fn (Server $server) => $this->suggestedLimits($server))
                    ->schema([
                        TextInput::make('memory')
                            ->label(trans('resource-advisor::strings.memory_limit'))
                            ->suffix('MiB')
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText(fn (Server $server) => trans('resource-advisor::strings.current', ['value' => $server->memory . ' MiB'])),
                        TextInput::make('disk')
                            ->label(trans('resource-advisor::strings.disk_limit'))
                            ->suffix('MiB')
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText(fn (Server $server) => trans('resource-advisor::strings.current', ['value' => $server->disk . ' MiB'])),
                        TextInput::make('cpu')
                            ->label(trans('resource-advisor::strings.cpu_limit'))
                            ->suffix('%')
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText(fn (Server $server) => trans('resource-advisor::strings.current', ['value' => $server->cpu . ' %'])),
                    ])
                    ->action(function (Server $server, array $data, BuildModificationService $buildService) {
                        abort_unless(user()?->can('update', $server), 403);

                        try {
                            // Pass every current limit through: the build service resets the ones it is not given.
                            $buildService->handle($server, [
                                'memory' => (int) $data['memory'],
                                'disk' => (int) $data['disk'],
                                'cpu' => (int) $data['cpu'],
                                'swap' => $server->swap,
                                'io' => $server->io,
                                'threads' => $server->threads,
                                'oom_killer' => $server->oom_killer,
                                'allocation_id' => $server->allocation_id,
                                'database_limit' => $server->database_limit,
                                'allocation_limit' => $server->allocation_limit,
                                'backup_limit' => $server->backup_limit,
                            ]);

                            Notification::make()
                                ->title(trans('resource-advisor::strings.applied'))
                                ->body(trans('resource-advisor::strings.applied_body', ['server' => $server->name]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            Notification::make()
                                ->title(trans('resource-advisor::strings.apply_failed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }

                        unset($this->results[$server->id]);
                        $this->allResults = null;
                    }),
            ])
            ->emptyStateHeading(trans('resource-advisor::strings.empty'));
    }

    /** @return array<string, mixed> */
    private function result(Server $server): array
    {
        if (!isset($this->results[$server->id])) {
            $this->results[$server->id] = app(Advisor::class)->analyse(collect([$server]))[$server->id];
        }

        return $this->results[$server->id];
    }

    /** @return array<int, array<string, mixed>> */
    private function allResults(): array
    {
        if ($this->allResults === null) {
            $this->allResults = [];
            Server::query()->select(['id', 'memory', 'disk', 'cpu', 'startup', 'image'])->chunkById(200, function ($servers) {
                $this->allResults += app(Advisor::class)->analyse($servers);
            });
        }

        return $this->allResults;
    }

    private function hasSuggestion(array $result): bool
    {
        return collect(['memory', 'disk', 'cpu'])->contains(fn ($key) => $result[$key]['suggested'] !== null);
    }

    /** @return array{memory: int, disk: int, cpu: int} */
    private function suggestedLimits(Server $server): array
    {
        $result = $this->result($server);

        return [
            'memory' => $result['memory']['suggested'] !== null ? intdiv($result['memory']['suggested'], self::MIB) : (int) $server->memory,
            'disk' => $result['disk']['suggested'] !== null ? intdiv($result['disk']['suggested'], self::MIB) : (int) $server->disk,
            'cpu' => $result['cpu']['suggested'] !== null ? (int) $result['cpu']['suggested'] : (int) $server->cpu,
        ];
    }

    private function color(string $status): ?string
    {
        return match ($status) {
            Advisor::INCREASE => 'danger',
            Advisor::DECREASE => 'warning',
            default => null,
        };
    }

    private function advice(string $type, array $data, callable $format): ?string
    {
        if ($data['status'] === Advisor::COLLECTING) {
            return trans('resource-advisor::strings.collecting');
        }

        if ($data['status'] === Advisor::UNLIMITED) {
            return trans('resource-advisor::strings.unlimited');
        }

        if ($data['suggested'] !== null) {
            return trans("resource-advisor::strings.reasons.$type.{$data['reason']}", [
                'value' => $format($data['suggested']),
                'count' => $data['oom_kills'] ?? 0,
                'days' => isset($data['days_left']) ? (int) floor($data['days_left']) : null,
            ]);
        }

        if (($data['reason'] ?? null) === 'java') {
            return trans('resource-advisor::strings.reasons.memory.java');
        }

        return null;
    }

    private function describeMemory(array $data): string
    {
        if ($data['peak'] <= 0) {
            return $data['limit'] > 0 ? trans('resource-advisor::strings.limit', ['value' => $this->bytes($data['limit'])]) : '-';
        }

        $limit = $data['limit'] > 0 ? $this->bytes($data['limit']) : '∞';
        $percent = $data['limit'] > 0 ? ' · ' . round($data['peak'] / $data['limit'] * 100) . ' %' : '';

        return trans('resource-advisor::strings.peak', ['value' => $this->bytes($data['peak'])]) . ' / ' . $limit . $percent;
    }

    private function describeDisk(array $data): string
    {
        $limit = $data['limit'] > 0 ? $this->bytes($data['limit']) : '∞';
        $percent = $data['limit'] > 0 ? ' · ' . round($data['usage'] / $data['limit'] * 100) . ' %' : '';

        return $this->bytes($data['usage']) . ' / ' . $limit . $percent;
    }

    private function describeCpu(array $data): string
    {
        if ($data['peak'] <= 0) {
            return $data['limit'] > 0 ? trans('resource-advisor::strings.limit', ['value' => $data['limit'] . ' %']) : '-';
        }

        $limit = $data['limit'] > 0 ? $data['limit'] . ' %' : '∞';

        return trans('resource-advisor::strings.peak', ['value' => $data['peak'] . ' %']) . ' / ' . $limit;
    }

    private function bytes(int|float $bytes): string
    {
        return Number::fileSize((int) $bytes, maxPrecision: 1);
    }
}
