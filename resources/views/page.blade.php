@php
    $nodes = $this->nodeCapacity();
@endphp
<x-filament-panels::page>
    @if (count($nodes))
        <x-filament::section collapsible :heading="trans('resource-advisor::strings.nodes.title')" :description="trans('resource-advisor::strings.nodes.help')">
            <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 22rem), 1fr));">
                @foreach ($nodes as $node)
                    <div style="border-radius: .75rem; padding: .9rem 1rem; background: rgba(127,127,127,.07); box-shadow: inset 0 0 0 1px rgba(127,127,127,.18);">
                        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: .5rem; margin-bottom: .6rem;">
                            <strong>{{ $node['name'] }}</strong>
                            <span style="opacity: .65; font-size: .8rem;">{{ trans_choice('resource-advisor::strings.nodes.servers', $node['servers'], ['count' => $node['servers']]) }}</span>
                        </div>
                        @foreach ($node['resources'] as $key => $res)
                            @php
                                $scale = max($res['sellable'], $res['reserved'], $res['used'], 1);
                                $reservedPct = min(100, $res['reserved'] / $scale * 100);
                                $usedPct = min(100, $res['used'] / $scale * 100);
                                $capacityPct = $res['capacity'] > 0 ? min(100, $res['capacity'] / $scale * 100) : null;
                                $over = $res['sellable'] > 0 && $res['reserved'] > $res['sellable'];
                                $color = ['memory' => '#3b82f6', 'disk' => '#22c55e', 'cpu' => '#f97316'][$key];
                            @endphp
                            <div style="margin-top: .55rem;">
                                <div style="display: flex; justify-content: space-between; gap: .5rem; font-size: .8rem; margin-bottom: .25rem;">
                                    <span style="font-weight: 600;">{{ $res['label'] }}</span>
                                    <span style="opacity: .75; text-align: right;">
                                        {{ trans('resource-advisor::strings.nodes.reserved', ['reserved' => $res['text']['reserved'], 'capacity' => $res['text']['capacity']]) }}
                                    </span>
                                </div>
                                <div style="position: relative; height: .55rem; border-radius: 9999px; background: rgba(127,127,127,.18); overflow: hidden;" title="{{ trans('resource-advisor::strings.nodes.bar_help') }}">
                                    <div style="position: absolute; inset: 0 auto 0 0; width: {{ $reservedPct }}%; background: {{ $over ? '#ef4444' : $color }}; opacity: .35;"></div>
                                    <div style="position: absolute; inset: 0 auto 0 0; width: {{ $usedPct }}%; background: {{ $color }};"></div>
                                    @if ($capacityPct !== null && $capacityPct < 100)
                                        <div style="position: absolute; top: 0; bottom: 0; left: {{ $capacityPct }}%; width: 2px; background: rgba(127,127,127,.9);"></div>
                                    @endif
                                </div>
                                <div style="display: flex; flex-wrap: wrap; gap: .15rem .9rem; font-size: .75rem; opacity: .75; margin-top: .3rem;">
                                    <span>{{ trans('resource-advisor::strings.nodes.used', ['value' => $res['text']['used']]) }}</span>
                                    <span @style(['color: #ef4444; font-weight: 600' => $over])>{{ $over ? trans('resource-advisor::strings.nodes.over') : trans('resource-advisor::strings.nodes.free', ['value' => $res['text']['free']]) }}</span>
                                    @if ($res['text']['reclaimable'])
                                        <span>{{ trans('resource-advisor::strings.nodes.reclaimable', ['value' => $res['text']['reclaimable']]) }}</span>
                                    @endif
                                    @if ($res['unlimited'])
                                        <span>{{ trans_choice('resource-advisor::strings.nodes.unlimited', $res['unlimited'], ['count' => $res['unlimited']]) }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
