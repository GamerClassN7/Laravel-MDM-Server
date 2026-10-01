{{-- Uptime Kuma style monitor of a ping-only device. --}}
@php
    $format = fn ($value, $unit) => $value === null ? '–' : rtrim(rtrim(number_format($value, $unit === '%' ? 2 : 1, '.', ''), '0'), '.').' '.$unit;
    $uptimeColor = fn ($value) => $value === null ? '' : ($value >= 99 ? 'text-success' : ($value >= 95 ? 'text-warning-emphasis' : 'text-danger'));
@endphp
<div class="card mt-3">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <span class="icon-tile {{ $device->offline ? 'bg-secondary-subtle' : 'bg-success-subtle text-success-emphasis' }}"><i class="fas fa-network-wired"></i></span>
            <div class="me-auto min-w-0">
                <div class="fw-semibold">{{ __('Ping') }} · <span class="font-monospace">{{ $device->ping_address }}</span></div>
                <div class="small text-muted">
                    @if ($relay)
                        {{ __('Every 30 s by :relay', ['relay' => $relay->displayName]) }}
                    @else
                        {{ __('No agent pings it: it needs an online agent 1.10.0+ in the same network that does not run on a battery.') }}
                    @endif
                    @if ($device->ping_mac)
                        · MAC <span class="font-monospace">{{ $device->ping_mac }}</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- The last checks, oldest left; empty slots until there are enough. --}}
        <div class="ping-beats mb-1" role="img" aria-label="{{ __('Last :count pings', ['count' => $beats->count()]) }}">
            @for ($i = $beats->count(); $i < \App\Livewire\PingMonitor::BEATS; $i++)
                <span class="ping-beat"></span>
            @endfor
            @foreach ($beats as $beat)
                <span class="ping-beat {{ $beat->up ? 'is-up' : 'is-down' }}" title="{{ $beat->created_at->format('j. n. H:i:s') }} · {{ $beat->up ? $format($beat->rtt, 'ms') : __('No answer') }}"></span>
            @endforeach
        </div>
        <div class="d-flex justify-content-between small text-muted mb-4">
            <span>{{ $beats->first()?->created_at->diffForHumans() ?? '' }}</span>
            <span>{{ __('now') }}</span>
        </div>

        <div class="row g-3 mb-4 text-center">
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

        <div class="d-flex align-items-center mb-2">
            <div class="small text-muted me-auto">{{ __('Response time') }}</div>
            <ul class="nav nav-switch" role="tablist">
                @foreach (array_keys(\App\Livewire\PingMonitor::RANGES) as $option)
                    <li class="nav-item">
                        <button class="nav-link {{ $range === $option ? 'active' : '' }}" type="button" wire:click="setRange('{{ $option }}')">{{ $option }}</button>
                    </li>
                @endforeach
            </ul>
        </div>
        @php
            $count = count($points);
            $x = fn ($i) => round($i / max(1, $count - 1) * 300, 2);
            $y = fn ($v) => round(100 - min(100, $v / $max * 90), 2);
            $segments = [];
            $run = [];
            foreach ($points as $i => $point) {
                if ($point === null) {
                    if ($run) { $segments[] = $run; }
                    $run = [];
                    continue;
                }
                $run[] = [$x($i), $y($point)];
            }
            if ($run) { $segments[] = $run; }
        @endphp
        <div class="position-relative" style="padding-right: 3rem;">
            <small class="position-absolute end-0 text-body-secondary lh-1" style="top: 10%; font-size: .65rem">{{ $format($max, 'ms') }}</small>
            <small class="position-absolute end-0 text-body-secondary lh-1" style="top: calc(100% - .7rem); font-size: .65rem">0 ms</small>
            <svg class="w-100 d-block" height="120" preserveAspectRatio="none" viewBox="0 0 300 100" role="img" aria-label="{{ __('Response time') }}" style="overflow: visible">
                @foreach ($downs as $i => $down)
                    @if ($down)
                        <rect fill="var(--bs-danger)" fill-opacity=".12" height="100" width="{{ round(300 / $count, 2) }}" x="{{ round($i * 300 / $count, 2) }}" y="0"></rect>
                    @endif
                @endforeach
                <line stroke="var(--bs-border-color)" stroke-dasharray="2 3" vector-effect="non-scaling-stroke" x1="0" x2="300" y1="10" y2="10"></line>
                <line stroke="var(--bs-border-color)" vector-effect="non-scaling-stroke" x1="0" x2="300" y1="100" y2="100"></line>
                @foreach ($segments as $segment)
                    @php($line = collect($segment)->map(fn ($p) => $p[0].','.$p[1])->implode(' '))
                    <path d="M{{ $segment[0][0] }},100 L{{ str_replace(' ', ' L', $line) }} L{{ end($segment)[0] }},100 Z" fill="var(--bs-success)" fill-opacity=".15"></path>
                    @if (count($segment) > 1)
                        <polyline fill="none" points="{{ $line }}" stroke="var(--bs-success)" stroke-linejoin="round" stroke-width="2" vector-effect="non-scaling-stroke"></polyline>
                    @else
                        <circle cx="{{ $segment[0][0] }}" cy="{{ $segment[0][1] }}" fill="var(--bs-success)" r="1.5"></circle>
                    @endif
                @endforeach
            </svg>
        </div>
        <div class="d-flex justify-content-between small text-muted mt-1" style="padding-right: 3rem">
            <span>{{ $from->format($range === '1h' || $range === '24h' ? 'H:i' : 'j. n.') }}</span>
            <span>{{ now()->format($range === '1h' || $range === '24h' ? 'H:i' : 'j. n.') }}</span>
        </div>
    </div>
</div>
