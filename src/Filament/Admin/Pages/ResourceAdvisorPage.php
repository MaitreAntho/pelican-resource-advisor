<?php

namespace MaitreAntho\ResourceAdvisor\Filament\Admin\Pages;

use App\Enums\CustomizationKey;
use App\Models\Node;
use App\Models\Server;
use App\Services\Servers\BuildModificationService;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use MaitreAntho\ResourceAdvisor\Models\IgnoredServer;
use MaitreAntho\ResourceAdvisor\Models\ResourceSample;
use Livewire\Attributes\Url;
use MaitreAntho\ResourceAdvisor\Services\Advisor;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResourceAdvisorPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-gauge';

    protected static ?string $slug = 'resource-advisor';

    protected static ?int $navigationSort = 5;

    protected string $view = 'resource-advisor::page';

    /** Kept in the address, so a link can open the page already filtered (the alerts use it). */
    #[Url]
    public ?array $tableFilters = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $results = null;

    private const MIB = 1048576;

    /** Its own permissions, shown as "Resource Advisor" in the role editor. Root admins always have them. */
    public const PERMISSION_VIEW = 'view resourceAdvisor';

    public const PERMISSION_UPDATE = 'update resourceAdvisor';

    public static function canAccess(): bool
    {
        return user()?->can(self::PERMISSION_VIEW) ?? false;
    }

    /** Applying limits or ignoring a server needs the plugin permission and the right to edit that server. */
    private function canManage(Server $server): bool
    {
        return (user()?->can(self::PERMISSION_UPDATE) ?? false) && user()->can('update', $server);
    }

    /** Servers on the nodes this administrator may see (a role can be limited to some nodes). */
    private function servers(): Builder
    {
        return Server::query()->whereIn('node_id', user()?->accessibleNodes()->pluck('id') ?? []);
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
        $counts = collect($this->results())->reject(fn ($result) => $result['ignored'])->countBy('status');

        return trans('resource-advisor::strings.summary', [
            'increase' => $counts->get(Advisor::INCREASE, 0),
            'decrease' => $counts->get(Advisor::DECREASE, 0),
            'collecting' => $counts->get(Advisor::COLLECTING, 0),
            'days' => (int) config('resource-advisor.window_days', 7),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(trans('resource-advisor::strings.export'))
                ->icon('tabler-file-download')
                ->color('gray')
                ->action(fn () => $this->export()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->servers()->with(['node', 'user']))
            ->defaultSort('name')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->deferFilters(false)
            ->columns([
                TextColumn::make('name')
                    ->label(trans('resource-advisor::strings.server'))
                    ->description(fn (Server $server) => $server->node->name . ' · ' . $server->user->username)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('advice_status')
                    ->label(trans('resource-advisor::strings.status'))
                    ->state(fn (Server $server) => $this->result($server)['ignored'] ? 'ignored' : $this->result($server)['status'])
                    ->formatStateUsing(fn (string $state) => trans("resource-advisor::strings.statuses.$state"))
                    ->description(fn (Server $server) => $this->ignoredDescription($this->result($server)))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        Advisor::INCREASE => 'danger',
                        Advisor::DECREASE => 'warning',
                        Advisor::OK => 'success',
                        default => 'gray',
                    })
                    ->sortable(query: fn (Builder $query, string $direction) => $this->sortByStatus($query, $direction)),
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
                        'ignored' => trans('resource-advisor::strings.statuses.ignored'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'] ?? null;
                        if (!$value) {
                            return $query;
                        }

                        $results = collect($this->results());
                        $ids = $value === 'ignored'
                            ? $results->filter(fn ($result) => $result['ignored'])->keys()->all()
                            : $results->reject(fn ($result) => $result['ignored'])
                                ->filter(fn ($result) => in_array($result['status'], $value === 'attention' ? [Advisor::INCREASE, Advisor::DECREASE] : [$value], true))
                                ->keys()->all();

                        return $query->whereIn('id', $ids);
                    }),
            ])
            ->recordActions([
                Action::make('usage')
                    ->label(trans('resource-advisor::strings.usage'))
                    ->tooltip(trans('resource-advisor::strings.usage'))
                    ->icon('tabler-chart-line')
                    ->modalHeading(fn (Server $server) => trans('resource-advisor::strings.usage_of', ['server' => $server->name]))
                    ->modalContent(fn (Server $server) => view('resource-advisor::usage', $this->usageData($server)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(trans('resource-advisor::strings.close'))
                    ->modalWidth('5xl'),
                Action::make('apply')
                    ->label(trans('resource-advisor::strings.apply'))
                    ->tooltip(trans('resource-advisor::strings.apply'))
                    ->icon('tabler-adjustments-up')
                    ->color('primary')
                    ->visible(fn (Server $server) => $this->canManage($server) && $this->hasSuggestion($this->result($server)))
                    ->modalHeading(fn (Server $server) => trans('resource-advisor::strings.apply_to', ['server' => $server->name]))
                    ->modalDescription(trans('resource-advisor::strings.apply_description'))
                    ->modalSubmitActionLabel(trans('resource-advisor::strings.apply'))
                    ->fillForm(fn (Server $server) => $this->suggestedLimits($server))
                    ->schema([
                        TextInput::make('memory')
                            ->label(trans('resource-advisor::strings.memory_limit'))
                            ->suffix('MiB')
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText(fn (Server $server) => $this->limitHelp($server, 'memory', $server->memory . ' MiB')),
                        TextInput::make('disk')
                            ->label(trans('resource-advisor::strings.disk_limit'))
                            ->suffix('MiB')
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText(fn (Server $server) => $this->limitHelp($server, 'disk', $server->disk . ' MiB')),
                        TextInput::make('cpu')
                            ->label(trans('resource-advisor::strings.cpu_limit'))
                            ->suffix('%')
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText(fn (Server $server) => $this->limitHelp($server, 'cpu', $server->cpu . ' %')),
                    ])
                    ->action(function (Server $server, array $data, BuildModificationService $buildService) {
                        abort_unless($this->canManage($server), 403);

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

                            $over = $this->overCapacity($server->node()->first());
                            if ($over) {
                                Notification::make()
                                    ->title(trans('resource-advisor::strings.node_over_title', ['node' => $server->node->name]))
                                    ->body(trans('resource-advisor::strings.node_over_body', ['resources' => implode(', ', $over)]))
                                    ->warning()
                                    ->persistent()
                                    ->send();
                            }
                        } catch (Exception $exception) {
                            report($exception);

                            Notification::make()
                                ->title(trans('resource-advisor::strings.apply_failed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }

                        $this->results = null;
                    }),
                ActionGroup::make([
                    Action::make('ignore')
                        ->label(trans('resource-advisor::strings.ignore'))
                        ->icon('tabler-eye-off')
                        ->visible(fn (Server $server) => $this->canManage($server) && !$this->result($server)['ignored'])
                        ->modalHeading(fn (Server $server) => trans('resource-advisor::strings.ignore_title', ['server' => $server->name]))
                        ->modalDescription(trans('resource-advisor::strings.ignore_description'))
                        ->modalSubmitActionLabel(trans('resource-advisor::strings.ignore'))
                        ->modalWidth('md')
                        ->schema([
                            Select::make('for')
                                ->label(trans('resource-advisor::strings.ignore_for'))
                                ->options([
                                    '7' => trans('resource-advisor::strings.ignore_days', ['days' => 7]),
                                    '30' => trans('resource-advisor::strings.ignore_days', ['days' => 30]),
                                    'forever' => trans('resource-advisor::strings.ignore_forever'),
                                ])
                                ->default('30')
                                ->selectablePlaceholder(false)
                                ->required(),
                        ])
                        ->action(function (Server $server, array $data) {
                            abort_unless($this->canManage($server), 403);
                            IgnoredServer::query()->updateOrCreate(['server_id' => $server->id], [
                                'until' => $data['for'] === 'forever' ? null : now()->addDays((int) $data['for']),
                                'created_at' => now(),
                            ]);
                            $this->results = null;
                        }),
                    Action::make('unignore')
                        ->label(trans('resource-advisor::strings.unignore'))
                        ->icon('tabler-eye')
                        ->visible(fn (Server $server) => $this->canManage($server) && $this->result($server)['ignored'])
                        ->action(function (Server $server) {
                            abort_unless($this->canManage($server), 403);
                            IgnoredServer::query()->where('server_id', $server->id)->delete();
                            $this->results = null;
                        }),
                ]),
            ])
            ->emptyStateHeading(trans('resource-advisor::strings.empty'));
    }

    /**
     * Reserved and used resources of every node, for the cards above the table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function nodeCapacity(): array
    {
        $results = $this->results();
        $servers = $this->servers()->get(['id', 'node_id', 'memory', 'disk', 'cpu'])->groupBy('node_id');
        $out = [];

        foreach (user()->accessibleNodes()->orderBy('name')->get() as $node) {
            $list = $servers->get($node->id, collect());
            $resources = [];

            foreach (['memory' => 'peak', 'disk' => 'usage', 'cpu' => 'peak'] as $key => $usedKey) {
                $isCpu = $key === 'cpu';
                $unit = $isCpu ? 1 : self::MIB;
                $reserved = $list->sum(fn (Server $server) => max(0, (int) $server->{$key})) * $unit;
                $used = $list->sum(fn (Server $server) => (float) ($results[$server->id][$key][$usedKey] ?? 0));
                $reclaimable = $list->sum(function (Server $server) use ($results, $key) {
                    $data = $results[$server->id][$key] ?? null;

                    return ($data && !$results[$server->id]['ignored'] && $data['status'] === Advisor::DECREASE) ? max(0, $data['limit'] - $data['suggested']) : 0;
                });
                $overallocate = (int) $node->{$key . '_overallocate'};
                $capacity = (int) $node->{$key} * $unit;
                $sellable = ($capacity > 0 && $overallocate >= 0) ? $capacity * (1 + $overallocate / 100) : 0;

                $format = fn ($v) => $isCpu ? round($v) . ' %' : $this->bytes($v);

                $resources[$key] = [
                    'label' => trans("resource-advisor::strings.$key"),
                    'capacity' => $capacity,
                    'sellable' => $sellable,
                    'reserved' => $reserved,
                    'used' => $used,
                    'reclaimable' => $reclaimable,
                    'unlimited' => $list->filter(fn (Server $server) => (int) $server->{$key} <= 0)->count(),
                    'text' => [
                        'capacity' => $capacity > 0 ? $format($capacity) : '∞',
                        'reserved' => $format($reserved),
                        'used' => $format($used),
                        'free' => $sellable > 0 ? $format(max(0, $sellable - $reserved)) : '∞',
                        'reclaimable' => $reclaimable > 0 ? $format($reclaimable) : null,
                    ],
                ];
            }

            $out[] = ['name' => $node->name, 'servers' => $list->count(), 'resources' => $resources];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function usageData(Server $server): array
    {
        $retention = max(1, (int) config('resource-advisor.retention_days', 35));
        $since = now()->subDays(min(30, $retention))->startOfHour();

        return [
            'server' => $server,
            'result' => $this->result($server),
            'rows' => ResourceSample::query()->where('server_id', $server->id)->where('hour', '>=', $since)->orderBy('hour')->get(),
            'oomKills' => app(Advisor::class)->oomKills([$server->id], $since)[$server->id] ?? [],
            'window' => (int) config('resource-advisor.window_days', 7),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function results(): array
    {
        if ($this->results === null) {
            // The advice is computed and cached for every server, then cut down to the visible ones.
            $visible = array_flip($this->servers()->pluck('id')->all());
            $this->results = array_intersect_key(app(Advisor::class)->all(), $visible);
        }

        return $this->results;
    }

    /** @return array<string, mixed> */
    private function result(Server $server): array
    {
        return $this->results()[$server->id] ?? app(Advisor::class)->analyse(collect([$server]))[$server->id];
    }

    private function sortByStatus(Builder $query, string $direction): Builder
    {
        $rank = [Advisor::INCREASE => 5, Advisor::DECREASE => 4, Advisor::COLLECTING => 3, Advisor::OK => 2];
        $groups = [];
        foreach ($this->results() as $id => $result) {
            $groups[$result['ignored'] ? 1 : ($rank[$result['status']] ?? 0)][] = (int) $id;
        }

        if (!$groups) {
            return $query;
        }

        $cases = collect($groups)->map(fn ($ids, $value) => 'WHEN id IN (' . implode(',', $ids) . ") THEN $value")->implode(' ');

        return $query->orderByRaw("CASE $cases ELSE 0 END " . ($direction === 'asc' ? 'asc' : 'desc'))->orderBy('name');
    }

    private function export(): StreamedResponse
    {
        $results = $this->results();
        $servers = $this->servers()->with(['node', 'user'])->orderBy('name')->get();
        $mib = fn ($bytes) => $bytes === null ? '' : (int) round($bytes / self::MIB);

        return response()->streamDownload(function () use ($servers, $results, $mib) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['server_id', 'server', 'node', 'owner', 'advice', 'ignored',
                'memory_limit_mib', 'memory_peak_mib', 'memory_status', 'memory_suggested_mib', 'oom_kills',
                'disk_limit_mib', 'disk_usage_mib', 'disk_days_left', 'disk_status', 'disk_suggested_mib',
                'cpu_limit_percent', 'cpu_peak_percent', 'cpu_status', 'cpu_suggested_percent']);

            foreach ($servers as $server) {
                $r = $results[$server->id] ?? null;
                if (!$r) {
                    continue;
                }

                fputcsv($out, [$server->id, $server->name, $server->node->name, $server->user->username, $r['status'], $r['ignored'] ? 'yes' : 'no',
                    $mib($r['memory']['limit']), $mib($r['memory']['peak']), $r['memory']['status'], $mib($r['memory']['suggested']), $r['memory']['oom_kills'],
                    $mib($r['disk']['limit']), $mib($r['disk']['usage']), $r['disk']['days_left'] === null || $r['disk']['days_left'] > 365 ? '' : (int) floor($r['disk']['days_left']), $r['disk']['status'], $mib($r['disk']['suggested']),
                    $r['cpu']['limit'], $r['cpu']['peak'], $r['cpu']['status'], $r['cpu']['suggested'] ?? '']);
            }

            fclose($out);
        }, 'resource-advisor-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function hasSuggestion(array $result): bool
    {
        return collect(['memory', 'disk', 'cpu'])->contains(fn ($key) => $result[$key]['suggested'] !== null && $result[$key]['status'] !== Advisor::COLLECTING);
    }

    /** @return array{memory: int, disk: int, cpu: int} */
    private function suggestedLimits(Server $server): array
    {
        $result = $this->result($server);
        $pick = fn ($key) => $result[$key]['suggested'] !== null && $result[$key]['status'] !== Advisor::COLLECTING ? $result[$key]['suggested'] : null;

        return [
            'memory' => $pick('memory') !== null ? intdiv($pick('memory'), self::MIB) : (int) $server->memory,
            'disk' => $pick('disk') !== null ? intdiv($pick('disk'), self::MIB) : (int) $server->disk,
            'cpu' => $pick('cpu') !== null ? (int) $pick('cpu') : (int) $server->cpu,
        ];
    }

    /** "Currently 4 GiB · node: 12 GiB free" */
    private function limitHelp(Server $server, string $key, string $current): string
    {
        $text = trans('resource-advisor::strings.current', ['value' => $current]);
        $node = $server->node;
        $capacity = (int) $node->{$key};
        $overallocate = (int) $node->{$key . '_overallocate'};

        if ($capacity <= 0 || $overallocate < 0) {
            return $text;
        }

        $free = $capacity * (1 + $overallocate / 100) - (int) Server::query()->where('node_id', $node->id)->where($key, '>', 0)->sum($key);
        $value = $key === 'cpu' ? round($free) . ' %' : $this->bytes(max(0, $free) * self::MIB);

        return $text . ' · ' . trans($free < 0 ? 'resource-advisor::strings.node_over' : 'resource-advisor::strings.node_free', ['value' => $free < 0 ? ($key === 'cpu' ? round(-$free) . ' %' : $this->bytes(-$free * self::MIB)) : $value]);
    }

    /** @return array<int, string> the resources the node has handed out beyond its capacity */
    private function overCapacity(Node $node): array
    {
        $over = [];
        foreach (['memory', 'disk', 'cpu'] as $key) {
            $capacity = (int) $node->{$key};
            $overallocate = (int) $node->{$key . '_overallocate'};
            if ($capacity <= 0 || $overallocate < 0) {
                continue;
            }

            $reserved = (int) Server::query()->where('node_id', $node->id)->where($key, '>', 0)->sum($key);
            if ($reserved > $capacity * (1 + $overallocate / 100)) {
                $over[] = mb_strtolower(trans("resource-advisor::strings.$key"));
            }
        }

        return $over;
    }

    private function ignoredDescription(array $result): ?string
    {
        if (!$result['ignored']) {
            return null;
        }

        if ($result['ignored_until'] === null) {
            return trans('resource-advisor::strings.ignored_forever');
        }

        return trans('resource-advisor::strings.ignored_until', ['date' => Carbon::parse($result['ignored_until'])->timezone(user()->timezone ?? 'UTC')->format('d/m')]);
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
        if (($data['reason'] ?? null) === 'confirming') {
            return trans('resource-advisor::strings.confirming', [
                'value' => $format($data['suggested']),
                'date' => Carbon::parse($data['confirm_at'])->timezone(user()->timezone ?? 'UTC')->format('d/m'),
            ]);
        }

        if ($data['status'] === Advisor::COLLECTING) {
            return isset($data['changed_at']) && $data['changed_at'] !== null
                ? trans('resource-advisor::strings.collecting_changed')
                : trans('resource-advisor::strings.collecting');
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
