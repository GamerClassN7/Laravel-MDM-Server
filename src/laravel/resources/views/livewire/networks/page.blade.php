{{-- The networks of the fleet: the map (App\Support\NetworkMap, drawn by resources/js/network-map.js) and the list. --}}
@php
    $counts = collect($map['nodes'])->countBy('kind');
    $kinds = [
        'lan' => ['icon' => 'fas fa-ethernet', 'label' => __('LAN'), 'tone' => 'primary'],
        'mixed' => ['icon' => 'fas fa-ethernet', 'label' => __('LAN + Wi-Fi'), 'tone' => 'primary'],
        'wifi' => ['icon' => 'fas fa-wifi', 'label' => __('Wi-Fi'), 'tone' => 'purple'],
        'cellular' => ['icon' => 'fas fa-signal', 'label' => __('Mobile'), 'tone' => 'primary'],
        'vpn' => ['icon' => 'fas fa-shield-alt', 'label' => __('VPN'), 'tone' => 'info'],
    ];
    $isolated = collect($map['nodes'])->where('kind', 'device')->where('isolated', true)->values();
@endphp
<div>
    <div class="container-fluid px-3 px-lg-4">
        <div class="page-header">
            <div class="me-auto min-w-0">
                <h1 class="mb-1">{{ __('Networks') }}</h1>
                <div class="small text-muted">
                    {{ trans_choice(':count public address|:count public addresses', $counts['site'] ?? 0) }} ·
                    {{ trans_choice(':count network|:count networks', $counts['network'] ?? 0) }} ·
                    {{ trans_choice(':count device|:count devices', $counts['device'] ?? 0) }} ·
                    {{ __('built from what the agents report') }}
                </div>
            </div>
        </div>

        @if (($counts['device'] ?? 0) === 0)
            <div class="card card-body text-center text-body-secondary py-5">
                <i class="fas fa-project-diagram fa-2x mb-3"></i>
                <div>{{ __('No devices yet: the map is built from what their agents report.') }}</div>
            </div>
        @else
            <div class="card card-body p-2 mb-4">
                {{-- Drawn and kept by the script (live updates replace its data, not the DOM). --}}
                <div class="network-map" wire:ignore
                    x-data="{ map: null }"
                    x-init="(window.mdmNetworkMap ? Promise.resolve() : new Promise((done) => window.addEventListener('load', done, { once: true }))).then(() => window.mdmNetworkMap($el, @js($map))).then((m) => map = m)"
                    x-on:network-map-updated.window="map?.update($event.detail.map)"></div>
                <div class="d-flex flex-wrap gap-3 small text-body-secondary px-2 pt-2 pb-1 border-top mt-2">
                    <span><span class="nm-dot is-up me-1"></span>{{ __('online') }}</span>
                    <span><span class="nm-dot is-offline me-1"></span>{{ __('offline') }}</span>
                    <span><span class="nm-dot is-warning me-1"></span>{{ __('no answer') }}</span>
                    @foreach (['wan' => __('internet'), 'uplink' => __('uplink'), 'lan' => __('LAN'), 'wifi' => __('Wi-Fi'), 'vpn' => __('VPN'), 'tunnel' => __('tunnel')] as $type => $label)
                        <span><svg class="me-1" height="6" width="22"><path class="nm-link is-{{ $type }}" d="M1 3 L 21 3"></path></svg>{{ $label }}</span>
                    @endforeach
                    <span><svg class="me-1" height="6" width="22"><path class="nm-link is-lan is-down" d="M1 3 L 21 3"></path></svg>{{ __('down') }}</span>
                    <span class="ms-auto d-none d-md-inline">{{ __('Drag to move, wheel or pinch to zoom.') }}</span>
                </div>
            </div>

            <h5 class="mb-3">{{ __('Networks') }}</h5>
            <div class="row g-3">
                @foreach ($map['networks'] as $network)
                    @php $kind = $kinds[$network['kind']] ?? $kinds['lan']; @endphp
                    <div class="col-12 col-lg-6 col-xxl-4" wire:key="network-{{ $network['id'] }}">
                        <div class="card card-body h-100">
                            <div class="d-flex align-items-start gap-3">
                                <span class="icon-tile bg-{{ $kind['tone'] }}-subtle text-{{ $kind['tone'] }}-emphasis"><i class="{{ $kind['icon'] }}"></i></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="fw-semibold">{{ $network['cidr'] }}</span>
                                        <x-badge :color="$kind['tone'] === 'purple' ? 'primary' : $kind['tone']" size="sm" variant="subtle">{{ $kind['label'] }}</x-badge>
                                        @if ($network['site'] && $network['site'] !== '?')
                                            <x-badge color="success" size="sm" variant="subtle">{{ $network['site'] }}</x-badge>
                                        @endif
                                    </div>
                                    <div class="small text-muted">
                                        {{ trans_choice(':count device|:count devices', count($network['devices'])) }} · {{ __(':count online', ['count' => $network['online']]) }}
                                        @if ($network['gateway'])
                                            · {{ __('gateway :address', ['address' => $network['gateway']]) }}
                                        @endif
                                    </div>
                                    @if ($network['kind'] !== 'vpn' && count($network['devices']) > 1)
                                        <div class="small mt-2">
                                            <i class="fas fa-satellite-dish text-body-secondary me-1"></i>
                                            @if ($network['relays'])
                                                {{ __('Can ping and wake: :agents', ['agents' => implode(', ', array_slice($network['relays'], 0, 3))]) }}
                                            @else
                                                <span class="text-warning-emphasis">{{ __('No online agent: nothing here can be pinged or woken') }}</span>
                                            @endif
                                        </div>
                                    @endif
                                    <div class="d-flex flex-wrap gap-1 mt-2">
                                        @foreach ($network['devices'] as $device)
                                            <a class="badge text-decoration-none border {{ $device['online'] ? 'border-success-subtle bg-success-subtle text-success-emphasis' : 'border-secondary-subtle bg-secondary-subtle text-body-secondary' }}"
                                                href="{{ route('devices', ['selectedDeviceId' => $device['id']]) }}">
                                                @if ($device['ping'])<i class="fas fa-network-wired me-1"></i>@endif{{ $device['name'] }}
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
                @foreach ($isolated as $node)
                    <div class="col-12 col-lg-6 col-xxl-4" wire:key="isolated-{{ $node['id'] }}">
                        <div class="card card-body h-100 border-warning-subtle">
                            <div class="d-flex align-items-start gap-3">
                                <span class="icon-tile bg-warning-subtle text-warning-emphasis"><i class="fas fa-exclamation-triangle"></i></span>
                                <div class="min-w-0">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <a class="fw-semibold text-body" href="{{ $node['url'] }}">{{ $node['label'] }}</a>
                                        <x-badge color="warning" size="sm" variant="subtle">{{ __('Isolated') }}</x-badge>
                                    </div>
                                    <div class="small text-muted">{{ __('No other agent in its networks: nothing next to it can wake it or ping the devices around it.') }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
