{{-- Uptime Kuma style monitor of a ping-only device. --}}
@php
    $format = fn ($value, $unit) => $value === null ? '–' : rtrim(rtrim(number_format($value, $unit === '%' ? 2 : 1, '.', ''), '0'), '.').' '.$unit;
    $uptimeColor = fn ($value) => $value === null ? '' : ($value >= 99 ? 'text-success' : ($value >= 95 ? 'text-warning-emphasis' : 'text-danger'));
@endphp
@php
    // The top of the response time chart: a round value above the highest point.
    $peak = max(1, (float) $max * 1.15);
    $step = 10 ** floor(log10($peak));
    $top = collect([1, 2, 5, 10])->map(fn ($m) => $m * $step)->first(fn ($v) => $v >= $peak);
    $subtitle = ($relay ? e(__('Every 30 s by :relay', ['relay' => $relay->displayName])) : e(__('No agent pings it: it needs an online agent 1.10.0+ in the same network that does not run on a battery.')))
        .($device->ping_mac ? ' · MAC <span class="font-monospace">'.e($device->ping_mac).'</span>' : '');
@endphp
{{-- Uptime Kuma style monitor of a ping-only device, laid out like the performance of an agent. --}}
<div class="mt-4">
    @include('partials.chart-section-header', [
        'icon' => 'fas fa-network-wired',
        'tile' => $device->offline ? 'bg-secondary-subtle' : 'bg-success-subtle text-success-emphasis',
        'title' => e(__('Ping')).' · <span class="font-monospace">'.e($device->ping_address).'</span>',
        'subtitle' => $subtitle,
        'ranges' => array_keys(\App\Livewire\PingMonitor::RANGES),
        'range' => $range,
    ])

    <div class="card">
        <div class="card-body">
            {{-- The last checks, oldest left; empty slots until there are enough. --}}
            <div class="ping-beats mb-1" role="img" aria-label="{{ __('Last :count pings', ['count' => $beats->count()]) }}">
                @for ($i = $beats->count(); $i < \App\Livewire\PingMonitor::BEATS; $i++)
                    <span class="ping-beat"></span>
                @endfor
                @foreach ($beats as $beat)
                    <span class="ping-beat {{ $beat->up ? 'is-up' : 'is-down' }}" title="{{ $beat->created_at->format('j. n. H:i:s') }} · {{ $beat->up ? $format($beat->rtt, 'ms') : __('No answer') }}"></span>
                @endforeach
            </div>
            <div class="d-flex justify-content-between small text-muted mb-3">
                <span>{{ $beats->first()?->created_at->diffForHumans() ?? '' }}</span>
                <span>{{ __('now') }}</span>
            </div>

            <div class="row g-3 text-center">
                @foreach ([
                    [__('Response'), __('now'), $format($stats['current'], 'ms'), ''],
                    [__('Average'), $range, $format($stats['average'], 'ms'), ''],
                    [__('Uptime'), '24 h', $format($stats['uptime24'], '%'), $uptimeColor($stats['uptime24'])],
                    [__('Uptime'), '30 d', $format($stats['uptime30'], '%'), $uptimeColor($stats['uptime30'])],
                ] as [$label, $period, $value, $class])
                    <div class="col-6 col-md-3">
                        <div class="small text-muted">{{ $label }} <span class="opacity-75">({{ $period }})</span></div>
                        <div class="fs-5 fw-semibold {{ $class }}">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- One chart over both columns of the performance section. --}}
    <div class="row g-3 mt-0">
        <div class="col-12">
            <x-metric-chart
                :current="$format($stats['current'], 'ms')"
                :from="$from->format($range === '1h' || $range === '24h' ? 'H:i' : 'j. n.')"
                :labels="$labels"
                :marks="$downs"
                :max="$top"
                :subtitle="__('Average :value', ['value' => $format($stats['average'], 'ms')]).' ('.$range.')'"
                :title="__('Response time')"
                :to="now()->format($range === '1h' || $range === '24h' ? 'H:i' : 'j. n.')"
                :values="$points"
                color="var(--bs-success)"
                unit="ms"
            />
        </div>
    </div>
</div>
