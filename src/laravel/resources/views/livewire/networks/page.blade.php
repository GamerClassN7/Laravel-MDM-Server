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
            <div class="nm-header-tools d-flex align-items-center flex-wrap gap-3">
                {{-- The map or the list, one at a time (the map fills the window, no page scroll). --}}
                <div class="btn-group btn-group-sm nm-view-toggle" role="group" aria-label="{{ __('View') }}">
                    <button class="btn {{ $view === 'map' ? 'btn-primary' : 'btn-light' }}" type="button" wire:click="$set('view', 'map')" aria-pressed="{{ $view === 'map' ? 'true' : 'false' }}">
                        <i class="fas fa-project-diagram me-1"></i>{{ __('Diagram') }}
                    </button>
                    <button class="btn {{ $view === 'list' ? 'btn-primary' : 'btn-light' }}" type="button" wire:click="$set('view', 'list')" aria-pressed="{{ $view === 'list' ? 'true' : 'false' }}">
                        <i class="fas fa-th-list me-1"></i>{{ __('Networks') }}
                        @if ($unknownCount > 0)<span class="badge rounded-pill text-bg-warning ms-1">{{ $unknownCount }}</span>@endif
                    </button>
                </div>
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

        @unless ($discovery)
            {{-- Off for the whole portal: no neighbours are taken and no scans sent, the unknown devices go stale. --}}
            <div class="alert alert-warning d-flex align-items-start gap-2 py-2 small mb-3" role="status">
                <i class="fas fa-eye-slash mt-1"></i>
                <div>
                    <strong>{{ __('Network discovery is off.') }}</strong>
                    {{ __('The portal takes no ARP tables from the agents and sends no scans, so unknown devices are not updated.') }}
                    @if ($admin) {{ __('Turn it on with the Network discovery switch above.') }} @endif
                </div>
            </div>
        @endunless

        @if (($counts['device'] ?? 0) === 0)
            <div class="card card-body text-center text-body-secondary py-5">
                <i class="fas fa-project-diagram fa-2x mb-3"></i>
                <div>{{ __('No devices yet: the map is built from what their agents report.') }}</div>
            </div>
        @elseif ($view === 'map')
            <div class="card card-body p-2 mb-3" wire:key="network-map-view">
                {{-- Drawn and kept by the script (live updates replace its data, not the DOM). As high as
                     the window leaves below it (the legend and the phone's bottom bar aside). --}}
                <div class="network-map" wire:ignore
                    x-data="{
                        map: null,
                        fill() {
                            const below = ($el.nextElementSibling?.offsetHeight ?? 0) + (document.querySelector('.layout-nav-mobile')?.offsetHeight ?? 0) + (window.innerWidth < 768 ? 56 : 48);
                            $el.style.height = Math.max(320, window.innerHeight - $el.getBoundingClientRect().top - below) + 'px';
                        },
                    }"
                    x-init="fill(); (window.mdmNetworkMap ? Promise.resolve() : new Promise((done) => window.addEventListener('load', done, { once: true }))).then(() => window.mdmNetworkMap($el, @js($map))).then((m) => map = m)"
                    x-on:resize.window.debounce.200ms="fill(); map?.fit()"
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

        @else
            {{-- Columns of cards of their own height (a small network is a small card). --}}
            <div class="nm-cards" wire:key="network-list-view">
                @foreach ($map['networks'] as $network)
                    @php $kind = $kinds[$network['kind']] ?? $kinds['lan']; @endphp
                    @php
                        // A scan by an agent here that allows it: what answers comes with its report.
                        $scan = $network['scan'];
                        $command = $scan && $scan['command'] ? \App\Models\DeviceCommand::find($scan['command']['id']) : null;
                    @endphp
                    <div class="nm-card" id="{{ $network['anchor'] }}" wire:key="network-{{ $network['id'] }}">
                        <div class="card card-body">
                            <div class="d-flex align-items-start gap-3">
                                {{-- On phones without the icon: the card's width for its devices. --}}
                                <span class="icon-tile d-none d-sm-inline-flex bg-{{ $kind['tone'] }}-subtle text-{{ $kind['tone'] }}-emphasis"><i class="{{ $kind['icon'] }}"></i></span>
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
                                    @if ($scan)
                                        {{-- The scan: its progress, its last result, or why nothing here can scan. --}}
                                        <div class="small mt-1">
                                            @if ($command?->active)
                                                @include('partials.device.command-progress', ['command' => $command, 'compact' => true, 'note' => $command->displayMessage ?: __('Scanning through :agent', ['agent' => $scan['command']['by']])])
                                            @elseif ($command)
                                                <span class="{{ $command->status === 'succeeded' ? 'text-body-secondary' : 'text-danger' }}"><i class="fas fa-search-location me-1"></i>{{ $command->displayMessage ?: $command->statusLabel }} · {{ $command->updated_at->diffForHumans() }}</span>
                                            @elseif (! $scan['agent'])
                                                <span class="text-body-secondary"><i class="fas fa-search-location me-1"></i>{{ $scan['refusal'] }}</span>
                                            @endif
                                            @error('scan.'.$network['anchor']) <div class="text-danger">{{ $message }}</div> @enderror
                                        </div>
                                    @endif
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
                                            <a class="nm-chip text-decoration-none border {{ $device['online'] ? 'border-success-subtle bg-success-subtle text-success-emphasis' : 'border-secondary-subtle bg-secondary-subtle text-body-secondary' }}"
                                                href="{{ route('devices', ['selectedDeviceId' => $device['id']]) }}">
                                                @if ($device['ping'])<i class="fas fa-network-wired"></i>@endif{{ $device['name'] }}
                                            </a>
                                        @endforeach
                                    </div>

                                    {{-- Devices its agents see that the portal does not know. --}}
                                    @if ($network['unknown'])
                                        <div class="small fw-medium text-muted mt-3 mb-1">{{ trans_choice(':count unknown device|:count unknown devices', count($network['unknown'])) }}</div>
                                        <ul class="list-unstyled mb-0 nm-unknown-list">
                                            @foreach ($network['unknown'] as $neighbour)
                                                <li class="d-flex align-items-start gap-2 py-2 border-top" wire:key="neighbour-{{ $neighbour['id'] }}">
                                                    <span class="nm-dot mt-2 {{ $neighbour['fresh'] ? 'is-up' : 'is-offline' }}" title="{{ $neighbour['fresh'] ? __('seen now') : __('not seen for a while') }}"></span>
                                                    <div class="min-w-0 flex-grow-1" title="{{ $neighbour['title'] }}">
                                                        {{-- Its name (or address) first, as on the map; the address and MAC under it. --}}
                                                        <div class="text-truncate fw-medium">{{ $neighbour['hostname'] ?: $neighbour['ip'] }}</div>
                                                        {{-- Wraps on phones instead of cutting the MAC address. --}}
                                                        <div class="text-body-secondary text-break" style="font-size: .75rem">
                                                            @if ($neighbour['hostname'])<span>{{ $neighbour['ip'] }}</span> · @endif<span class="font-monospace">{{ $neighbour['mac'] }}</span>
                                                            @if ($neighbour['random']) · <span title="{{ __('A private address the device made up for this network: it may change.') }}">{{ __('random MAC') }}</span>@endif
                                                        </div>
                                                    </div>
                                                    <button class="btn btn-sm btn-outline-primary nm-icon-btn" type="button" wire:click="add({{ $neighbour['id'] }})" aria-label="{{ __('Add') }}" title="{{ __('Add as a ping-only device (it follows its MAC address to a new IP)') }}"><i class="fas fa-plus"></i></button>
                                                    <button class="btn btn-sm btn-outline-secondary nm-icon-btn" type="button" wire:click="ignore({{ $neighbour['id'] }})" title="{{ __('Ignore') }}" aria-label="{{ __('Ignore') }}"><i class="fas fa-eye-slash"></i></button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                </div>
                                @if ($scan)
                                    <button class="btn btn-sm btn-outline-primary text-nowrap flex-shrink-0 nm-scan-btn" type="button" wire:click="scan(@js($network['id']))" aria-label="{{ __('Scan') }}" wire:loading.attr="disabled"
                                        @disabled(! $scan['agent'] || $command?->active)
                                        title="{{ $scan['agent'] ? __('Ping every address of the network from :agent and take what answers', ['agent' => $scan['name']]) : $scan['refusal'] }}">
                                        @if ($command?->active)
                                            <span aria-hidden="true" class="spinner-border spinner-border-sm me-sm-1"></span>
                                        @else
                                            <i class="fas fa-search-location me-sm-1"></i>
                                        @endif
                                        <span class="d-none d-sm-inline">{{ __('Scan') }}</span>
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
                @foreach ($isolated as $node)
                    <div class="nm-card" wire:key="isolated-{{ $node['id'] }}">
                        <div class="card card-body border-warning-subtle">
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
