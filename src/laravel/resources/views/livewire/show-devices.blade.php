<div>
    <div class="container-xl">
        @if ($addDevice)
            <nav aria-label="breadcrumb" class="small mb-2">
                <a class="text-body-secondary text-decoration-none" href="#" wire:click.prevent="showList">{{ __('Devices') }}</a>
                <span class="text-body-secondary mx-1">/</span>{{ __('Add device') }}
            </nav>
            <div class="btn-group mb-3" role="group" aria-label="{{ __('Kind of device') }}">
                <input autocomplete="off" class="btn-check" id="add-mode-agent" type="radio" value="agent" wire:model.live="addMode">
                <label class="btn btn-outline-secondary" for="add-mode-agent"><i class="fas fa-robot me-1"></i>{{ __('With the agent') }}</label>
                <input autocomplete="off" class="btn-check" id="add-mode-ping" type="radio" value="ping" wire:model.live="addMode">
                <label class="btn btn-outline-secondary" for="add-mode-ping"><i class="fas fa-network-wired me-1"></i>{{ __('Ping only') }}</label>
            </div>
            @if ($addMode === 'ping')
                <div class="card">
                    <form class="card-body" wire:submit="createPingDevice">
                        <h5 class="card-title">{{ __('Add a ping-only device') }}</h5>
                        <p class="text-muted">{{ __('For a printer, a NAS or a PC without the agent: an agent (1.10.0+, not on a battery) in the same network pings it every 30 seconds for its online status and wakes it with Wake-on-LAN.') }}</p>
                        <div class="row g-3" style="max-width: 44rem">
                            <div class="col-12">
                                <label class="form-label" for="ping-name">{{ __('Name') }}</label>
                                <input class="form-control @error('pingName') is-invalid @enderror" id="ping-name" placeholder="{{ __('e.g. Printer') }}" type="text" wire:model="pingName">
                                @error('pingName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-8 col-md-6">
                                <label class="form-label" for="ping-address">{{ __('IPv4 address') }}</label>
                                <input class="form-control font-monospace @error('pingAddress') is-invalid @enderror" id="ping-address" placeholder="192.168.1.50" type="text" wire:model="pingAddress">
                            </div>
                            <div class="col-4 col-md-2">
                                <label class="form-label" for="ping-prefix">{{ __('Prefix') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text">/</span>
                                    <input class="form-control" id="ping-prefix" max="30" min="8" type="number" wire:model="pingPrefix">
                                </div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="ping-mac">{{ __('MAC address') }} <span class="text-muted">({{ __('for Wake-on-LAN') }})</span></label>
                                <input class="form-control font-monospace" id="ping-mac" placeholder="AA:BB:CC:DD:EE:FF" type="text" wire:model="pingMac">
                            </div>
                            @error('pingAddress') <div class="col-12 small text-danger">{{ $message }}</div> @enderror
                        </div>
                        <button class="btn btn-primary mt-3" type="submit"><i class="fas fa-plus me-2"></i>{{ __('Add device') }}</button>
                    </form>
                </div>
            @else
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">{{ __('Enrol new device') }}</h5>
                    <p class="text-muted mb-2">{{ __('Enter this code in the agent to register the device.') }}</p>
                    <h2 class="display-5 fw-semibold">{{ $enrollmentCode }}</h2>
                    <p class="text-muted">
                        <i class="far fa-clock me-1"></i>{{ __('Expires') }} {{ $enrollmentCodeExpiration->diffForHumans() }} ({{ $enrollmentCodeExpiration }})
                    </p>

                    <h6 class="mt-4">{{ __('Install the agent') }}</h6>
                    <div x-data="{ tab: 'windows' }">
                        <ul class="nav nav-tabs mb-2">
                            <li class="nav-item">
                                <button class="nav-link" type="button" x-bind:class="{ active: tab === 'windows' }" x-on:click="tab = 'windows'">
                                    <i class="fab fa-windows me-2"></i>Windows PowerShell
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" type="button" x-bind:class="{ active: tab === 'pwsh' }" x-on:click="tab = 'pwsh'">
                                    <i class="fas fa-terminal me-2"></i>PowerShell 7 (Windows, Linux)
                                </button>
                            </li>
                        </ul>

                        @foreach (['windows' => __('Run in PowerShell as Administrator.'), 'pwsh' => __('Run in pwsh as Administrator on Windows, or with sudo pwsh on Debian / Ubuntu.')] as $variant => $hint)
                            <div x-show="tab === '{{ $variant }}'" @if ($variant !== 'windows') x-cloak style="display: none" @endif>
                                <x-copy-command :command="$installCommands[$variant]" />
                                <p class="small text-muted mt-2 mb-0">{{ $hint }}</p>
                            </div>
                        @endforeach
                    </div>

                    <p class="small text-muted mt-3 mb-0" title="{{ __('The agent accepts only commands, responses and updates signed with this key. It logs the fingerprint it pinned in agent.log.') }}">
                        <i class="fas fa-key me-1"></i>{{ __('Server key fingerprint') }}:
                        <code class="text-break">{{ \App\Support\Signing::fingerprint() }}</code>
                    </p>
                </div>
            </div>
            @endif
        @elseif ($selectedDevice)
            <div class="row g-4">
                {{-- Quick switching between devices on wide screens; phones go back to the overview. --}}
                <aside class="col-lg-3 d-none d-lg-block">
                    <div class="device-switcher" wire:poll.30s>
                        <div class="d-flex align-items-center mb-2">
                            <a class="small text-body-secondary text-decoration-none me-auto" href="{{ route('devices') }}" wire:click.prevent="showList"><i class="fas fa-th-list me-1"></i>{{ __('All devices') }}</a>
                            @if ($filtered)
                                <span class="small text-muted">{{ __('Filtered') }}</span>
                            @endif
                        </div>
                        <div class="list-group">
                            @foreach ($rows as $row)
                                @php($item = $row['device'])
                                <a class="list-group-item list-group-item-action d-flex align-items-start gap-2 py-2 {{ $item->id === $selectedDevice->id ? 'active' : '' }}"
                                    href="{{ route('devices', ['selectedDeviceId' => $item->id]) }}" wire:click.prevent="selectDevice({{ $item->id }})" wire:key="switch-device-{{ $item->id }}"
                                    @if ($item->id === $selectedDevice->id) aria-current="true" @endif>
                                    <span class="device-dot mt-2 {{ $item->offline ? 'is-offline' : 'is-online' }}" title="{{ $item->offline ? __('Offline') : __('Online') }}"></span>
                                    <span class="min-w-0 flex-grow-1">
                                        <span class="d-block text-truncate fw-medium"><i class="{{ $item->typeIcon }} fa-fw me-1 opacity-75"></i>{{ $item->displayName }}</span>
                                        <x-tags class="mt-1" :tags="$item->tagList" />
                                    </span>
                                    @if ($row['attention'] && ! $item->offline)
                                        <i class="fas fa-exclamation-circle text-warning mt-1" title="{{ collect($row['flags'])->pluck('label')->implode(', ') }}"></i>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                </aside>
                <div class="col-12 col-lg-9">
                    <nav aria-label="breadcrumb" class="small mb-2">
                        <a class="text-body-secondary text-decoration-none" href="{{ route('devices') }}" wire:click.prevent="showList"><i class="fas fa-arrow-left me-1"></i>{{ __('Devices') }}</a>
                        <span class="text-body-secondary mx-1">/</span>{{ $selectedDevice->displayName }}
                    </nav>
                    @livewire('device-detail', ['selectedDeviceId' => $selectedDevice->id], key($selectedDevice->id))
                </div>
            </div>
        @else
            {{-- The fleet overview. --}}
            <div wire:poll.30s>
                <div class="page-header">
                    <div class="me-auto">
                        <h1>{{ __('Devices') }}</h1>
                        <div class="text-muted">
                            {{ __(':online online · :offline offline · :attention need attention', ['online' => $counts['online'], 'offline' => $counts['offline'], 'attention' => $counts['attention']]) }}
                        </div>
                    </div>
                    <button class="btn btn-primary" type="button" wire:click="$set('addDevice', true)">
                        <i class="fas fa-plus me-2"></i>{{ __('Add device') }}
                    </button>
                </div>

                @if ($counts['all'] > 0)
                    <div class="row g-3 mb-4">
                        @foreach ([
                            ['label' => __('Online'), 'value' => __(':online of :all', ['online' => $counts['online'], 'all' => $counts['all']]), 'icon' => 'fas fa-signal', 'color' => 'success'],
                            ['label' => __('Pending updates'), 'value' => $counts['updates'] > 0 ? trans_choice(':count on :devices device|:count on :devices devices', $counts['updateDevices'], ['count' => $counts['updates'], 'devices' => $counts['updateDevices']]) : __('None'), 'icon' => 'fas fa-sync', 'color' => 'warning'],
                            ['label' => __('Firing alerts'), 'value' => $counts['firing'], 'icon' => 'fas fa-bell', 'color' => 'danger', 'href' => route('notifications')],
                            ['label' => __('Remediations compliant'), 'value' => $counts['runs'] > 0 ? __(':compliant of :runs', ['compliant' => $counts['compliant'], 'runs' => $counts['runs']]) : '–', 'icon' => 'fas fa-scroll', 'color' => 'primary'],
                        ] as $stat)
                            <div class="col-6 col-xl-3">
                                <div class="card card-body h-100">
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="icon-tile bg-{{ $stat['color'] }}-subtle text-{{ $stat['color'] }}-emphasis" style="width: 2.5rem; height: 2.5rem"><i class="{{ $stat['icon'] }}"></i></span>
                                        <div class="min-w-0">
                                            <div class="small text-muted">{{ $stat['label'] }}</div>
                                            @isset($stat['href'])
                                                <a class="fs-5 fw-semibold text-body text-decoration-none" href="{{ $stat['href'] }}">{{ $stat['value'] }}</a>
                                            @else
                                                <div class="fs-5 fw-semibold text-truncate">{{ $stat['value'] }}</div>
                                            @endisset
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <div class="position-relative" style="width: 18rem; max-width: 100%">
                        <i class="fas fa-search position-absolute top-50 translate-middle-y text-body-secondary small" style="left: .75rem"></i>
                        <input aria-label="{{ __('Search devices') }}" class="form-control" placeholder="{{ __('Search devices, OS, IP…') }}" style="padding-left: 2.1rem" type="search" wire:model.live.debounce.300ms="search">
                    </div>
                    <div class="btn-group" role="group" aria-label="{{ __('Status') }}">
                        @foreach (['' => [__('All'), $counts['all']], 'attention' => [__('Needs attention'), $counts['attention']], 'offline' => [__('Offline'), $counts['offline']]] as $value => [$label, $count])
                            <button class="btn {{ $status === $value ? 'btn-primary' : 'btn-outline-secondary' }}" type="button" aria-pressed="{{ $status === $value ? 'true' : 'false' }}" wire:click="filterStatus('{{ $value }}')">
                                {{ $label }} <span class="opacity-75">{{ $count }}</span>
                            </button>
                        @endforeach
                    </div>
                    @if ($tags !== [])
                        <div class="mdm-tags">
                            @foreach ($tags as $tagOption)
                                <x-tag as="button" :tag="$tagOption" :active="strcasecmp($tag, $tagOption) === 0" wire:click="filterTag(@js($tagOption))" />
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="card overflow-hidden">
                    @if ($selected !== [])
                        <div class="d-flex flex-wrap align-items-center gap-2 px-3 py-2 bg-primary-subtle text-primary-emphasis border-bottom border-primary-subtle">
                            <span class="fw-semibold me-1">{{ trans_choice(':count selected|:count selected', count($selected), ['count' => count($selected)]) }}</span>
                            <button class="btn btn-sm btn-light" type="button" wire:click="bulkCommand('doUpdates')" wire:confirm="{{ __('Install all updates on the selected devices?') }}"><i class="fas fa-sync me-1"></i>{{ __('Install updates') }}</button>
                            <button class="btn btn-sm btn-light" type="button" wire:click="bulkCommand('restart')" wire:confirm="{{ __('Restart the selected devices now? Unsaved work of their users is lost.') }}"><i class="fas fa-redo me-1"></i>{{ __('Restart') }}</button>
                            @can('is-system-admin')
                                <button class="btn btn-sm btn-light" type="button" wire:click="bulkRunScript"><i class="fas fa-scroll me-1"></i>{{ __('Run script…') }}</button>
                            @endcan
                            <div class="input-group input-group-sm" style="width: 16rem">
                                <input aria-label="{{ __('Tag') }}" class="form-control @error('bulkTag') is-invalid @enderror" list="bulk-tag-options" placeholder="{{ __('Tag') }}" type="text" wire:model="bulkTag" wire:keydown.enter="bulkTagChange(true)">
                                <button class="btn btn-light" type="button" wire:click="bulkTagChange(true)" title="{{ __('Add the tag') }}"><i class="fas fa-tag me-1"></i>{{ __('Add') }}</button>
                                <button class="btn btn-light" type="button" wire:click="bulkTagChange(false)" title="{{ __('Remove the tag') }}">{{ __('Remove') }}</button>
                            </div>
                            <datalist id="bulk-tag-options">
                                @foreach ($tags as $tagOption)
                                    <option value="{{ $tagOption }}"></option>
                                @endforeach
                            </datalist>
                            <button class="btn btn-sm btn-link text-primary-emphasis ms-auto" type="button" wire:click="$set('selected', [])">{{ __('Clear') }}</button>
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 device-table">
                            <thead>
                                <tr class="small text-body-secondary">
                                    <th class="ps-3" style="width: 2.5rem">
                                        <input aria-label="{{ __('Select all') }}" class="form-check-input" type="checkbox" @checked($rows->isNotEmpty() && count($selected) === $rows->count())
                                            wire:click="$set('selected', {{ count($selected) === $rows->count() ? '[]' : json_encode($rows->map(fn ($row) => (string) $row['device']->id)->values()) }})">
                                    </th>
                                    <th>{{ __('Device') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th class="d-none d-lg-table-cell">{{ __('System') }}</th>
                                    <th class="d-none d-md-table-cell">{{ __('Updates') }}</th>
                                    <th class="d-none d-xl-table-cell" style="width: 11rem">{{ __('Fullest drive') }}</th>
                                    <th class="d-none d-md-table-cell">{{ __('Attention') }}</th>
                                    <th class="pe-3 text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    @php($device = $row['device'])
                                    <tr wire:key="device-row-{{ $device->id }}" class="{{ in_array((string) $device->id, $selected, true) ? 'table-active' : '' }}">
                                        <td class="ps-3">
                                            <input aria-label="{{ __('Select :device', ['device' => $device->displayName]) }}" class="form-check-input" type="checkbox" value="{{ $device->id }}" wire:model.live="selected">
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-start gap-2">
                                                <i class="{{ $device->typeIcon }} fa-fw text-body-secondary mt-1" title="{{ __(ucfirst($device->type)) }}"></i>
                                                <div class="min-w-0">
                                                    <a class="fw-semibold text-body text-decoration-none" href="{{ route('devices', ['selectedDeviceId' => $device->id]) }}" wire:click.prevent="selectDevice({{ $device->id }})">{{ $device->displayName }}</a>
                                                    <x-tags class="mt-1" :tags="$device->tagList" />
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-nowrap">
                                            @if ($device->offline)
                                                <x-badge color="secondary" size="sm" variant="subtle" title="{{ ($device->last_seen_at ?? $device->updated_at)?->toDateTimeString() }}">{{ __('Offline') }} · {{ ($device->last_seen_at ?? $device->updated_at)?->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE, true) }}</x-badge>
                                            @else
                                                <x-badge color="success" size="sm" variant="subtle">{{ __('Online') }}</x-badge>
                                            @endif
                                        </td>
                                        <td class="d-none d-lg-table-cell">
                                            <div class="text-truncate" style="max-width: 14rem">{{ $device->isPingOnly ? __('Ping only') : ($device->os ?: '–') }}</div>
                                            @if ($row['ip'])
                                                <div class="small text-muted">{{ $row['ip'] }}</div>
                                            @endif
                                        </td>
                                        <td class="d-none d-md-table-cell text-nowrap">
                                            @if ($row['updates'] > 0)
                                                <x-badge color="warning" size="sm" variant="subtle">{{ trans_choice(':count pending|:count pending', $row['updates'], ['count' => $row['updates']]) }}</x-badge>
                                            @elseif (! empty($device->data))
                                                <span class="small text-muted">{{ __('Up to date') }}</span>
                                            @endif
                                        </td>
                                        <td class="d-none d-xl-table-cell">
                                            @if ($row['beats'] !== null)
                                                <div class="ping-beats ping-beats-sm" role="img" aria-label="{{ __('Last pings') }}">
                                                    @for ($i = $row['beats']->count(); $i < 20; $i++)
                                                        <span class="ping-beat"></span>
                                                    @endfor
                                                    @foreach ($row['beats'] as $beat)
                                                        <span class="ping-beat {{ $beat->up ? 'is-up' : 'is-down' }}" title="{{ $beat->created_at->format('H:i:s') }} · {{ $beat->up ? $beat->rtt.' ms' : __('No answer') }}"></span>
                                                    @endforeach
                                                </div>
                                                <div class="small text-muted mt-1">{{ $row['uptime'] === null ? __('No pings yet') : __(':uptime % uptime (24 h)', ['uptime' => $row['uptime']]) }}</div>
                                            @elseif ($row['drive'])
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1" style="height: 6px" role="progressbar" aria-label="{{ __('Fullest drive') }}" aria-valuenow="{{ $row['drive']['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                                                        <div class="progress-bar {{ $row['drive']['percent'] >= 95 ? 'bg-danger' : ($row['drive']['percent'] >= 90 ? 'bg-warning' : '') }}" style="width: {{ $row['drive']['percent'] }}%"></div>
                                                    </div>
                                                    <span class="small text-nowrap" style="width: 2.75rem; text-align: right">{{ $row['drive']['percent'] }} %</span>
                                                </div>
                                                <div class="small text-muted">{{ __(':free free', ['free' => $row['drive']['free']]) }}</div>
                                            @endif
                                        </td>
                                        <td class="d-none d-md-table-cell">
                                            <div class="d-flex flex-wrap gap-1">
                                                @foreach ($row['flags'] as $flag)
                                                    <x-badge :color="$flag['severity']" size="sm" variant="subtle" title="{{ $flag['title'] }}">{{ $flag['label'] }}</x-badge>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="pe-3 text-end text-nowrap">
                                            <a class="btn btn-sm btn-light" href="{{ route('devices', ['selectedDeviceId' => $device->id]) }}" wire:click.prevent="selectDevice({{ $device->id }})" aria-label="{{ __('Open :device', ['device' => $device->displayName]) }}">
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="text-center text-muted py-5" colspan="8">
                                            @if ($counts['all'] === 0)
                                                {{ __('No devices enrolled yet.') }}
                                                <a href="#" wire:click.prevent="$set('addDevice', true)">{{ __('Add the first one') }}</a>
                                            @else
                                                {{ __('No device matches the filters.') }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
