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
    $unknownCount = collect($map['networks'])->sum(fn ($network) => count($network['unknown']));
    $admin = auth()->user()?->can('is-system-admin');
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
                    @if ($unknownCount > 0)
                        {{ trans_choice(':count unknown|:count unknown', $unknownCount) }} ·
                    @endif
                    {{ __('built from what the agents report') }}
                </div>
            </div>
            {{-- Discovery: the agents' ARP tables and scans (each agent allows it in its config.json). --}}
            <div class="d-flex align-items-center gap-2">
                @if ($admin)
                    <div class="form-check form-switch mb-0" title="{{ __('Off: the portal takes no neighbours from the agents and sends no scans, whatever their config.json allows.') }}">
                        <input class="form-check-input" id="network-discovery" type="checkbox" role="switch" wire:click="toggleDiscovery" @checked($discovery)>
                        <label class="form-check-label small" for="network-discovery">{{ __('Network discovery') }}</label>
                    </div>
                @elseif (! $discovery)
                    <x-badge color="secondary" size="sm" variant="subtle">{{ __('Network discovery off') }}</x-badge>
                @endif
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
                    <span><svg class="me-1" height="6" width="22"><path class="nm-link is-unknown" d="M1 3 L 21 3"></path></svg>{{ __('unknown device') }}</span>
                    <span class="ms-auto d-none d-md-inline">{{ __('Drag to move, wheel or pinch to zoom.') }}</span>
                </div>
            </div>

            <h5 class="mb-3">{{ __('Networks') }}</h5>
            <div class="row g-3">
                @foreach ($map['networks'] as $network)
                    @php $kind = $kinds[$network['kind']] ?? $kinds['lan']; @endphp
                    <div class="col-12 col-lg-6 col-xxl-4" id="{{ $network['anchor'] }}" wire:key="network-{{ $network['id'] }}">
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

                                    {{-- Devices its agents see that the portal does not know. --}}
                                    @if ($network['unknown'])
                                        <div class="small fw-medium text-muted mt-3 mb-1">{{ trans_choice(':count unknown device|:count unknown devices', count($network['unknown'])) }}</div>
                                        <ul class="list-unstyled mb-0 nm-unknown-list">
                                            @foreach ($network['unknown'] as $neighbour)
                                                <li class="d-flex align-items-center gap-2 py-1 border-top" wire:key="neighbour-{{ $neighbour['id'] }}">
                                                    <span class="nm-dot {{ $neighbour['fresh'] ? 'is-up' : 'is-offline' }}" title="{{ $neighbour['fresh'] ? __('seen now') : __('not seen for a while') }}"></span>
                                                    <div class="min-w-0 flex-grow-1" title="{{ $neighbour['title'] }}">
                                                        {{-- Its name (or address) first, as on the map; the address and MAC under it. --}}
                                                        <div class="text-truncate fw-medium">{{ $neighbour['hostname'] ?: $neighbour['ip'] }}</div>
                                                        <div class="text-body-secondary text-truncate" style="font-size: .75rem">
                                                            @if ($neighbour['hostname'])<span>{{ $neighbour['ip'] }}</span> · @endif<span class="font-monospace">{{ $neighbour['mac'] }}</span>
                                                            @if ($neighbour['random']) · <span title="{{ __('A private address the device made up for this network: it may change.') }}">{{ __('random MAC') }}</span>@endif
                                                        </div>
                                                    </div>
                                                    <button class="btn btn-sm btn-outline-primary text-nowrap" type="button" wire:click="add({{ $neighbour['id'] }})" title="{{ __('Add as a ping-only device (it follows its MAC address to a new IP)') }}"><i class="fas fa-plus"></i><span class="d-none d-sm-inline ms-1">{{ __('Add') }}</span></button>
                                                    <button class="btn btn-sm btn-outline-secondary" type="button" wire:click="ignore({{ $neighbour['id'] }})" title="{{ __('Ignore') }}"><i class="fas fa-eye-slash"></i></button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    {{-- A scan by an agent here that allows it: the found devices come with its next report. --}}
                                    @if ($network['scan'])
                                        @php
                                            $scan = $network['scan'];
                                            $command = $scan['command'] ? \App\Models\DeviceCommand::find($scan['command']['id']) : null;
                                        @endphp
                                        <div class="mt-3 pt-2 border-top">
                                            @if ($command?->active)
                                                @include('partials.device.command-progress', ['command' => $command, 'note' => $command->displayMessage ?: __('Scanning through :agent', ['agent' => $scan['command']['by']])])
                                            @else
                                                <div class="d-flex align-items-center gap-2">
                                                    <button class="btn btn-sm btn-outline-primary text-nowrap" type="button" wire:click="scan(@js($network['id']))" wire:loading.attr="disabled" @disabled(! $scan['agent'])
                                                        title="{{ $scan['agent'] ? __('Ping every address of the network from :agent and take what answers', ['agent' => $scan['name']]) : $scan['refusal'] }}">
                                                        <i class="fas fa-satellite-dish me-1"></i>{{ __('Scan') }}
                                                    </button>
                                                    <span class="small text-body-secondary min-w-0">
                                                        @if ($command)
                                                            <span class="{{ $command->status === 'succeeded' ? '' : 'text-danger' }}">{{ $command->displayMessage ?: $command->statusLabel }}</span> · {{ $command->updated_at->diffForHumans() }}
                                                        @elseif ($scan['agent'])
                                                            {{ __('through :agent', ['agent' => $scan['name']]) }}
                                                        @else
                                                            {{ $scan['refusal'] }}
                                                        @endif
                                                    </span>
                                                </div>
                                            @endif
                                            @error('scan.'.$network['anchor']) <div class="small text-danger mt-1">{{ $message }}</div> @enderror
                                        </div>
                                    @endif
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

            @if ($ignored->isNotEmpty())
                <details class="mt-4">
                    <summary class="small text-body-secondary">{{ trans_choice(':count ignored device|:count ignored devices', $ignored->count()) }}</summary>
                    <ul class="list-unstyled small mt-2 mb-0">
                        @foreach ($ignored as $neighbour)
                            <li class="d-flex align-items-center gap-2 py-1" wire:key="ignored-{{ $neighbour->id }}">
                                <span class="fw-medium">{{ $neighbour->ip }}</span>
                                <span class="text-body-secondary text-truncate">{{ collect([$neighbour->hostname, $neighbour->mac, $neighbour->network, __('last seen :time', ['time' => $neighbour->last_seen_at->diffForHumans()])])->filter()->implode(' · ') }}</span>
                                <button class="btn btn-sm btn-link p-0 ms-auto" type="button" wire:click="restore({{ $neighbour->id }})">{{ __('Show again') }}</button>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @endif
    </div>
</div>
