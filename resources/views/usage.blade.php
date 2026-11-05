@php
    $mib = 1048576;
    $charts = [
        ['key' => 'memory', 'label' => trans('resource-advisor::strings.memory'), 'values' => $rows->map(fn ($r) => $r->running_samples > 0 ? $r->memory_max : null)->all(), 'limit' => $result['memory']['limit'], 'bytes' => true, 'color' => '#3b82f6'],
        ['key' => 'disk', 'label' => trans('resource-advisor::strings.disk'), 'values' => $rows->map(fn ($r) => $r->disk_max)->all(), 'limit' => $result['disk']['limit'], 'bytes' => true, 'color' => '#22c55e'],
        ['key' => 'cpu', 'label' => trans('resource-advisor::strings.cpu'), 'values' => $rows->map(fn ($r) => $r->running_samples > 0 ? $r->cpu_max : null)->all(), 'limit' => $result['cpu']['limit'], 'bytes' => false, 'color' => '#f97316'],
    ];
    $format = fn ($v, $bytes) => $bytes ? \Illuminate\Support\Number::fileSize((int) $v, maxPrecision: 1) : round($v) . ' %';
    $first = $rows->first()?->hour;
    $last = $rows->last()?->hour;
    $hours = $rows->map(fn ($r) => $r->hour->timezone(user()->timezone ?? 'UTC')->format('d/m H:i'))->values()->all();
@endphp

<div style="display: grid; gap: 1.25rem;">
    @if ($rows->isEmpty())
        <p style="opacity: .7;">{{ trans('resource-advisor::strings.no_data') }}</p>
    @else
        <p style="opacity: .7; font-size: .875rem;">
            {{ trans('resource-advisor::strings.range', ['from' => $first->timezone(user()->timezone ?? 'UTC')->format('d/m H:i'), 'to' => $last->timezone(user()->timezone ?? 'UTC')->format('d/m H:i'), 'hours' => $rows->count()]) }}
        </p>
        @foreach ($charts as $chart)
            @php
                $values = array_values($chart['values']);
                $known = array_filter($values, fn ($v) => $v !== null);
                $top = max(1, $chart['limit'] ?: 0, $known ? max($known) : 0) * 1.08;
                $w = 900; $h = 150; $n = max(1, count($values) - 1);
                $points = [];
                $segments = [];
                foreach ($values as $i => $v) {
                    if ($v === null) { if ($points) { $segments[] = $points; $points = []; } continue; }
                    $points[] = round($i / $n * $w, 1) . ',' . round($h - $v / $top * $h, 1);
                }
                if ($points) { $segments[] = $points; }
                $limitY = $chart['limit'] > 0 ? round($h - $chart['limit'] / $top * $h, 1) : null;
                $tips = array_map(fn ($v) => $v === null ? null : $format($v, $chart['bytes']), $values);
                $tops = array_map(fn ($v) => $v === null ? null : round((1 - $v / $top) * 100, 2), $values);
            @endphp
            <div>
                <div style="display: flex; justify-content: space-between; font-size: .875rem; margin-bottom: .35rem;">
                    <strong>{{ $chart['label'] }}</strong>
                    <span style="opacity: .7;">
                        {{ trans('resource-advisor::strings.highest', ['value' => $known ? $format(max($known), $chart['bytes']) : '-']) }}
                        @if ($chart['limit'] > 0) · {{ trans('resource-advisor::strings.limit', ['value' => $format($chart['limit'], $chart['bytes'])]) }} @endif
                    </span>
                </div>
                <div
                    x-data="{ i: null, hours: @js($hours), tips: @js($tips), tops: @js($tops), pick(x) { const r = $el.getBoundingClientRect(), n = this.hours.length; this.i = Math.max(0, Math.min(n - 1, Math.round((x - r.left) / r.width * (n - 1)))) }, left() { return this.hours.length < 2 ? 50 : this.i / (this.hours.length - 1) * 100 } }"
                    x-on:mousemove="pick($event.clientX)"
                    x-on:touchmove.passive="pick($event.touches[0].clientX)"
                    x-on:mouseleave="i = null"
                    x-on:touchend="i = null"
                    style="position: relative; cursor: crosshair;"
                >
                <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" style="display: block; width: 100%; height: 150px; border-radius: .5rem; background: rgba(127,127,127,.08);" role="img" aria-label="{{ $chart['label'] }}">
                    @if ($limitY !== null)
                        <line x1="0" x2="{{ $w }}" y1="{{ $limitY }}" y2="{{ $limitY }}" stroke="#ef4444" stroke-width="1.5" stroke-dasharray="6 5" vector-effect="non-scaling-stroke" />
                    @endif
                    @foreach ($segments as $segment)
                        @if (count($segment) === 1)
                            @php [$x, $y] = explode(',', $segment[0]); @endphp
                            <circle cx="{{ $x }}" cy="{{ $y }}" r="3" fill="{{ $chart['color'] }}" />
                        @else
                            <polygon points="0,{{ $h }} {{ implode(' ', $segment) }} {{ $w }},{{ $h }}" fill="{{ $chart['color'] }}" fill-opacity="0" />
                            <polyline points="{{ implode(' ', $segment) }}" fill="none" stroke="{{ $chart['color'] }}" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
                        @endif
                    @endforeach
                </svg>
                    <template x-if="i !== null">
                        <div style="position: absolute; inset: 0; pointer-events: none;">
                            <div :style="{ left: left() + '%' }" style="position: absolute; top: 0; bottom: 0; width: 1px; background: rgba(127,127,127,.6);"></div>
                            <div x-show="tops[i] !== null" :style="{ left: left() + '%', top: tops[i] + '%' }" style="position: absolute; width: 9px; height: 9px; margin: -4.5px 0 0 -4.5px; border-radius: 9999px; background: {{ $chart['color'] }}; box-shadow: 0 0 0 2px rgba(0,0,0,.5);"></div>
                            <div
                                :style="{ left: left() + '%', transform: 'translateX(' + (left() < 12 ? '0' : left() > 88 ? '-100%' : '-50%') + ')', top: tops[i] !== null && tops[i] < 50 ? 'auto' : '.4rem', bottom: tops[i] !== null && tops[i] < 50 ? '.4rem' : 'auto' }"
                                style="position: absolute; padding: .3rem .55rem; border-radius: .4rem; background: rgba(17,24,39,.95); color: #fff; font-size: .75rem; line-height: 1.3; white-space: nowrap; box-shadow: 0 4px 12px rgba(0,0,0,.35);"
                            >
                                <div x-text="hours[i]" style="opacity: .7;"></div>
                                <strong x-text="tips[i] ?? @js(trans('resource-advisor::strings.stopped'))"></strong>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        @endforeach
        <p style="opacity: .7; font-size: .8rem;">{{ trans('resource-advisor::strings.legend') }}</p>
    @endif
</div>
