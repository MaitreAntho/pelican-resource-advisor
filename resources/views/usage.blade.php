@php
    $mib = 1048576;
    $tz = user()->timezone ?? 'UTC';
    $format = fn ($v, $bytes) => $v === null ? null : ($bytes ? \Illuminate\Support\Number::fileSize((int) $v, maxPrecision: 1) : round($v) . ' %');
    $byHour = $rows->keyBy(fn ($r) => $r->hour->getTimestamp());
    $kills = collect($oomKills)->countBy(fn ($at) => $at->copy()->startOfHour()->getTimestamp());
    $current = ['memory' => (int) $server->memory * $mib, 'disk' => (int) $server->disk * $mib, 'cpu' => (float) $server->cpu];

    $periods = collect([1, $window, 30])->unique()->sort()->values()->all();
    $views = [];
    foreach ($periods as $days) {
        $end = now()->startOfHour();
        $hours = [];
        for ($t = $end->copy()->subHours($days * 24 - 1); $t->lte($end); $t->addHour()) {
            $hours[] = $t->getTimestamp();
        }

        $measured = collect($hours)->filter(fn ($h) => $byHour->has($h));
        $charts = [];
        foreach ([
            ['key' => 'memory', 'bytes' => true, 'color' => '#3b82f6', 'running' => true],
            ['key' => 'disk', 'bytes' => true, 'color' => '#22c55e', 'running' => false],
            ['key' => 'cpu', 'bytes' => false, 'color' => '#f97316', 'running' => true],
        ] as $def) {
            $key = $def['key'];
            $max = [];
            $avg = [];
            $limits = [];
            $states = [];
            foreach ($hours as $h) {
                $row = $byHour->get($h);
                $running = $row && $row->running_samples > 0;
                $states[] = !$row ? 'none' : ($def['running'] && !$running ? 'stopped' : 'ok');
                $max[] = !$row ? null : ($def['running'] ? ($running ? (float) $row->{$key . '_max'} : null) : (float) $row->disk_max);
                $avg[] = $def['running'] && $running ? (float) $row->{$key . '_sum'} / $row->running_samples : null;
                $rowLimit = $row?->{$key . '_limit'};
                $limits[] = $rowLimit !== null ? ($def['bytes'] ? (int) $rowLimit * $mib : (float) $rowLimit) : $current[$key];
            }

            $known = array_filter(array_merge($max, array_filter($limits)), fn ($v) => $v !== null);
            $top = max(1, $known ? max($known) : 0) * 1.08;
            $w = 900; $h = 150; $n = max(1, count($hours) - 1);
            $x = fn ($i) => round($i / $n * $w, 1);
            $y = fn ($v) => round($h - $v / $top * $h, 1);

            $line = function (array $values) use ($x, $y) {
                $segments = [];
                $points = [];
                foreach ($values as $i => $v) {
                    if ($v === null) { if ($points) { $segments[] = $points; $points = []; } continue; }
                    $points[] = $x($i) . ',' . $y($v);
                }
                if ($points) { $segments[] = $points; }

                return $segments;
            };

            // The limit as steps, so a change of limit shows where it happened.
            $limitPoints = [];
            $changes = [];
            foreach ($limits as $i => $l) {
                if ($l <= 0) { continue; }
                if ($i > 0 && $limits[$i - 1] > 0 && $limits[$i - 1] != $l) {
                    $limitPoints[] = $x($i) . ',' . $y($limits[$i - 1]);
                    $changes[] = $x($i);
                }
                $limitPoints[] = $x($i) . ',' . $y($l);
            }
            if (count($limitPoints) === 1) {
                $limitPoints[] = $w . ',' . explode(',', $limitPoints[0])[1];
            }

            $oom = [];
            if ($key === 'memory') {
                foreach ($hours as $i => $hr) {
                    if ($kills->has($hr)) { $oom[] = $x($i); }
                }
            }

            $knownMax = array_filter($max, fn ($v) => $v !== null);
            $charts[] = $def + [
                'label' => trans("resource-advisor::strings.$key"),
                'w' => $w, 'h' => $h,
                'max' => $line($max),
                'avg' => $line($avg),
                'limit' => $limitPoints,
                'changes' => $changes,
                'oom' => $oom,
                'highest' => $knownMax ? $format(max($knownMax), $def['bytes']) : '-',
                'tips' => [
                    'max' => array_map(fn ($v) => $format($v, $def['bytes']), $max),
                    'avg' => array_map(fn ($v) => $format($v, $def['bytes']), $avg),
                    'limit' => array_map(fn ($v) => $v > 0 ? $format($v, $def['bytes']) : '∞', $limits),
                    'state' => $states,
                    'oom' => $key === 'memory' ? array_map(fn ($hr) => $kills->get($hr, 0), $hours) : [],
                    'top' => array_map(fn ($v) => $v === null ? null : round((1 - $v / $top) * 100, 2), $max),
                ],
            ];
        }

        $views[$days] = [
            'labels' => array_map(fn ($hr) => \Illuminate\Support\Carbon::createFromTimestamp($hr)->timezone($tz)->format('d/m H:i'), $hours),
            'measured' => $measured->count(),
            'from' => \Illuminate\Support\Carbon::createFromTimestamp($hours[0])->timezone($tz)->format('d/m H:i'),
            'to' => \Illuminate\Support\Carbon::createFromTimestamp(end($hours))->timezone($tz)->addHour()->format('d/m H:i'),
            'charts' => $charts,
        ];
    }
    $t = [
        'stopped' => trans('resource-advisor::strings.stopped'),
        'none' => trans('resource-advisor::strings.not_measured'),
        'average' => trans('resource-advisor::strings.average_short'),
        'limit' => trans('resource-advisor::strings.limit_short'),
        'oom' => trans('resource-advisor::strings.oom_short'),
    ];
@endphp

<div x-data="{ period: {{ (int) $window }} }" style="display: grid; gap: 1.25rem;">
    @if ($rows->isEmpty())
        <p style="opacity: .7;">{{ trans('resource-advisor::strings.no_data') }}</p>
    @else
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem;">
            <div role="tablist" style="display: inline-flex; padding: .2rem; gap: .2rem; border-radius: .6rem; background: rgba(127,127,127,.12);">
                @foreach ($periods as $days)
                    <button type="button" role="tab" x-on:click="period = {{ $days }}" :aria-selected="period === {{ $days }}"
                        :style="period === {{ $days }} ? { background: 'rgba(59,130,246,.9)', color: '#fff' } : { background: 'transparent', color: 'inherit' }"
                        style="padding: .3rem .8rem; border-radius: .45rem; font-size: .8rem; font-weight: 600; cursor: pointer; border: 0;">
                        {{ $days === 1 ? trans('resource-advisor::strings.period_hours', ['hours' => 24]) : trans('resource-advisor::strings.period_days', ['days' => $days]) }}
                    </button>
                @endforeach
            </div>
            @foreach ($views as $days => $view)
                <p x-show="period === {{ $days }}" @if ($days !== (int) $window) style="display: none; opacity: .7; font-size: .875rem;" @else style="opacity: .7; font-size: .875rem;" @endif>
                    {{ trans('resource-advisor::strings.range', ['from' => $view['from'], 'to' => $view['to'], 'hours' => $view['measured']]) }}
                </p>
            @endforeach
        </div>

        @foreach ($views as $days => $view)
            <div x-show="period === {{ $days }}" @if ($days !== (int) $window) style="display: none;" @endif>
                <div style="display: grid; gap: 1.25rem;">
                    @foreach ($view['charts'] as $chart)
                        <div>
                            <div style="display: flex; justify-content: space-between; gap: .5rem; font-size: .875rem; margin-bottom: .35rem;">
                                <strong>{{ $chart['label'] }}</strong>
                                <span style="opacity: .7; text-align: right;">
                                    {{ trans('resource-advisor::strings.highest', ['value' => $chart['highest']]) }}
                                    @if ($current[$chart['key']] > 0) · {{ trans('resource-advisor::strings.limit', ['value' => $format($current[$chart['key']], $chart['bytes'])]) }} @endif
                                    @if (count($chart['oom'])) · <span style="color: #ef4444; font-weight: 600;">{{ trans('resource-advisor::strings.oom_count', ['count' => count($chart['oom'])]) }}</span> @endif
                                </span>
                            </div>
                            <div
                                x-data="{ i: null, labels: @js($view['labels']), tips: @js($chart['tips']), pick(x) { const r = $el.getBoundingClientRect(), n = this.labels.length; this.i = Math.max(0, Math.min(n - 1, Math.round((x - r.left) / r.width * (n - 1)))) }, left() { return this.labels.length < 2 ? 50 : this.i / (this.labels.length - 1) * 100 } }"
                                x-on:mousemove="pick($event.clientX)"
                                x-on:touchmove.passive="pick($event.touches[0].clientX)"
                                x-on:mouseleave="i = null"
                                x-on:touchend="i = null"
                                style="position: relative; cursor: crosshair;"
                            >
                                <svg viewBox="0 0 {{ $chart['w'] }} {{ $chart['h'] }}" preserveAspectRatio="none" style="display: block; width: 100%; height: 150px; border-radius: .5rem; background: rgba(127,127,127,.08);" role="img" aria-label="{{ $chart['label'] }}">
                                    @foreach ($chart['oom'] as $ox)
                                        <line x1="{{ $ox }}" x2="{{ $ox }}" y1="0" y2="{{ $chart['h'] }}" stroke="#ef4444" stroke-width="2" vector-effect="non-scaling-stroke" opacity=".85" />
                                    @endforeach
                                    @foreach ($chart['changes'] as $cx)
                                        <line x1="{{ $cx }}" x2="{{ $cx }}" y1="0" y2="{{ $chart['h'] }}" stroke="#ef4444" stroke-width="1" stroke-dasharray="2 4" vector-effect="non-scaling-stroke" opacity=".6" />
                                    @endforeach
                                    @if (count($chart['limit']) > 1)
                                        <polyline points="{{ implode(' ', $chart['limit']) }}" fill="none" stroke="#ef4444" stroke-width="1.5" stroke-dasharray="6 5" vector-effect="non-scaling-stroke" />
                                    @endif
                                    @foreach ($chart['avg'] as $segment)
                                        @if (count($segment) > 1)
                                            <polyline points="{{ implode(' ', $segment) }}" fill="none" stroke="{{ $chart['color'] }}" stroke-width="1.25" stroke-opacity=".45" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
                                        @endif
                                    @endforeach
                                    @foreach ($chart['max'] as $segment)
                                        @if (count($segment) === 1)
                                            @php [$px, $py] = explode(',', $segment[0]); @endphp
                                            <circle cx="{{ $px }}" cy="{{ $py }}" r="3" fill="{{ $chart['color'] }}" />
                                        @else
                                            <polyline points="{{ implode(' ', $segment) }}" fill="none" stroke="{{ $chart['color'] }}" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
                                        @endif
                                    @endforeach
                                </svg>
                                <template x-if="i !== null">
                                    <div style="position: absolute; inset: 0; pointer-events: none;">
                                        <div :style="{ left: left() + '%' }" style="position: absolute; top: 0; bottom: 0; width: 1px; background: rgba(127,127,127,.6);"></div>
                                        <div x-show="tips.top[i] !== null" :style="{ left: left() + '%', top: tips.top[i] + '%' }" style="position: absolute; width: 9px; height: 9px; margin: -4.5px 0 0 -4.5px; border-radius: 9999px; background: {{ $chart['color'] }}; box-shadow: 0 0 0 2px rgba(0,0,0,.5);"></div>
                                        <div
                                            :style="{ left: left() + '%', transform: 'translateX(' + (left() < 12 ? '0' : left() > 88 ? '-100%' : '-50%') + ')', top: tips.top[i] !== null && tips.top[i] < 50 ? 'auto' : '.4rem', bottom: tips.top[i] !== null && tips.top[i] < 50 ? '.4rem' : 'auto' }"
                                            style="position: absolute; padding: .3rem .55rem; border-radius: .4rem; background: rgba(17,24,39,.95); color: #fff; font-size: .75rem; line-height: 1.35; white-space: nowrap; box-shadow: 0 4px 12px rgba(0,0,0,.35);"
                                        >
                                            <div x-text="labels[i]" style="opacity: .7;"></div>
                                            <strong x-text="tips.max[i] ?? (tips.state[i] === 'none' ? @js($t['none']) : @js($t['stopped']))"></strong>
                                            <div x-show="tips.avg[i] !== null" style="opacity: .8;"><span>{{ $t['average'] }}</span> <span x-text="tips.avg[i]"></span></div>
                                            <div style="opacity: .8;"><span>{{ $t['limit'] }}</span> <span x-text="tips.limit[i]"></span></div>
                                            <div x-show="tips.oom.length && tips.oom[i] > 0" style="color: #fca5a5; font-weight: 600;"><span>{{ $t['oom'] }}</span> <span x-text="'×' + tips.oom[i]"></span></div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
        <p style="opacity: .7; font-size: .8rem;">{{ trans('resource-advisor::strings.legend') }}</p>
    @endif
</div>
