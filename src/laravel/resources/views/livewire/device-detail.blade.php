<div>
    @php
        $hasUpdates = count($selectedDevice->updates) > 0 || count($selectedDevice->apps_packages_updates) > 0;
        $power = $selectedDevice->data->machine->Battery ?? null;
        $services = $selectedDevice->services;
        $failedServices = collect($services)->where('State', '!=', 'running')->count();
        $docker = $selectedDevice->docker;
        $stoppedContainers = collect($docker['containers'] ?? [])->where('State', '!=', 'running')->count();
        $diskHealth = $selectedDevice->diskHealth;
    @endphp

    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div title="{{ $selectedDevice->updated_at->diffForHumans() }}">
                    @if ($editMode)
                        <div class="d-flex gap-2">
                            <input class="form-control" id="friendlyName" type="text" wire:model="friendlyName" wire:keydown.enter="saveFriendlyName">
                            <button class="btn btn-primary" type="button" wire:click="saveFriendlyName">{{ __('Save') }}</button>
                        </div>
                    @else
                        <h2 class="mb-1">
                            @if (count($selectedDevice->updates) > 1)
                                <i class="fas fa-exclamation-triangle text-danger me-2" title="{{ __('Updates available') }}"></i>
                            @elseif ($hasUpdates)
                                <i class="fas fa-exclamation-triangle text-warning me-2" title="{{ __('Updates available') }}"></i>
                            @endif
                            <i class="{{ $selectedDevice->typeIcon }} text-body-secondary me-2" title="{{ __(ucfirst($selectedDevice->type)) }}"></i>
                            {{ $selectedDevice->DisplayName }}
                            <button class="btn btn-sm btn-sq" type="button" title="{{ __('Rename') }}" wire:click="$set('editMode', true)">
                                <i class="fas fa-pen"></i>
                            </button>
                        </h2>
                    @endif

                    @if (!$selectedDevice->offline)
                        <div class="text-muted small d-flex flex-wrap gap-3">
                            @if (!empty($selectedDevice->lastLogonUser))
                                <span><i class="fas fa-user me-1"></i>{{ $selectedDevice->lastLogonUser }}</span>
                            @endif
                            @if (!empty($selectedDevice->NiceUptime))
                                <span><i class="far fa-clock me-1"></i>{{ $selectedDevice->NiceUptime }}</span>
                            @endif
                            @if (!empty($selectedDevice->os))
                                <span><i class="fas fa-info-circle me-1"></i>{{ $selectedDevice->os }}</span>
                            @endif
                            @if (!empty($selectedDevice->data->machine->Processor))
                                <span><i class="fas fa-microchip me-1"></i>{{ $selectedDevice->data->machine->Processor }} ({{ __(':count cores', ['count' => $selectedDevice->data->machine->Cores ?? '?']) }})</span>
                            @endif
                        </div>
                    @endif
                </div>

                @if (!$selectedDevice->offline)
                    <div class="fs-5 text-nowrap">
                        @if ($power !== null && $power !== [])
                            @if ($power < 20)
                                <i class="fas fa-battery-quarter text-danger"></i>
                            @elseif ($power < 85)
                                <i class="fas fa-battery-half"></i>
                            @else
                                <i class="fas fa-battery-full"></i>
                            @endif
                            {{ $power }} %
                        @else
                            <i class="fas fa-plug" title="{{ __('Plugged in') }}"></i>
                        @endif
                    </div>
                @endif
            </div>

            @if (!empty($selectedDevice->data))
                @livewire('device-alerts', ['selectedDeviceId' => $selectedDevice->id], key('device-alerts' . $selectedDevice->id))
            @endif
            @livewire('device-commands', ['selectedDeviceId' => $selectedDevice->id], key('device-commands' . $selectedDevice->id))
        </div>
    </div>

    @livewire('device-metrics', ['selectedDeviceId' => $selectedDevice->id], key('device-metrics' . $selectedDevice->id))

    <div class="mt-4">
        <ul class="nav nav-tabs" role="tablist">
            @if (!empty($selectedDevice->drives))
                <li class="nav-item" role="presentation">
                    <button aria-controls="drives-tab-pane" aria-selected="true" class="nav-link active" data-bs-target="#drives-tab-pane" data-bs-toggle="tab" id="drives-tab" role="tab" type="button">
                        <i class="fas fa-hdd me-2"></i>{{ __('Drives') }}
                    </button>
                </li>
            @endif
            @if ($hasUpdates)
                <li class="nav-item" role="presentation">
                    <button aria-controls="updates-tab-pane" aria-selected="false" class="nav-link" data-bs-target="#updates-tab-pane" data-bs-toggle="tab" id="updates-tab" role="tab" type="button">
                        <i class="fas fa-sync me-2"></i>{{ __('Updates') }}
                    </button>
                </li>
            @endif
            @if (count($selectedDevice->networks) > 0)
                <li class="nav-item" role="presentation">
                    <button aria-controls="networks-tab-pane" aria-selected="false" class="nav-link" data-bs-target="#networks-tab-pane" data-bs-toggle="tab" id="networks-tab" role="tab" type="button">
                        <i class="fas fa-network-wired me-2"></i>{{ __('Networks') }}
                    </button>
                </li>
            @endif
            @if (count($services) > 0)
                <li class="nav-item" role="presentation">
                    <button aria-controls="services-tab-pane" aria-selected="false" class="nav-link" data-bs-target="#services-tab-pane" data-bs-toggle="tab" id="services-tab" role="tab" type="button">
                        <i class="fas fa-cogs me-2"></i>{{ __('Services') }}
                        @if ($failedServices > 0)
                            <x-badge class="ms-1" color="danger" size="sm" title="{{ __('Not running') }}">{{ $failedServices }}</x-badge>
                        @endif
                    </button>
                </li>
            @endif
            @if ($docker !== null)
                <li class="nav-item" role="presentation">
                    <button aria-controls="docker-tab-pane" aria-selected="false" class="nav-link" data-bs-target="#docker-tab-pane" data-bs-toggle="tab" id="docker-tab" role="tab" type="button">
                        <i class="fab fa-docker me-2"></i>{{ __('Docker') }}
                        @if ($stoppedContainers > 0)
                            <x-badge class="ms-1" color="secondary" size="sm" title="{{ __('Not running') }}">{{ $stoppedContainers }}</x-badge>
                        @endif
                    </button>
                </li>
            @endif
            @if ($diskHealth !== null)
                <li class="nav-item" role="presentation">
                    <button aria-controls="health-tab-pane" aria-selected="false" class="nav-link" data-bs-target="#health-tab-pane" data-bs-toggle="tab" id="health-tab" role="tab" type="button">
                        <i class="fas fa-heartbeat me-2"></i>{{ __('Disk health') }}
                        @if ($selectedDevice->diskHealthProblem)
                            <i class="fas fa-exclamation-triangle text-danger ms-1"></i>
                        @endif
                    </button>
                </li>
            @endif
        </ul>

        <div class="tab-content pt-3">
            @if (!empty($selectedDevice->drives))
                <div aria-labelledby="drives-tab" class="tab-pane fade show active" id="drives-tab-pane" role="tabpanel" tabindex="0">
                    <div class="row g-3">
                        @foreach ($selectedDevice->drives as $drive)
                            <div class="col-12 col-md-6">
                                <div class="d-flex align-items-center gap-3">
                                    <i class="fas {{ $drive['DriveType'] == 5 ? 'fa-compact-disc' : 'fa-hdd' }} fa-2x text-muted"></i>
                                    <div class="flex-grow-1">
                                        <div>{{ $drive['FriendlyName'] ?? '' }} ({{ $drive['DriveLetter'] }})</div>
                                        @if (isset($drive['Size']) && isset($drive['PercentUsed']))
                                            <div class="progress my-1" style="height: 6px;">
                                                <div aria-valuemax="100" aria-valuemin="0" aria-valuenow="{{ $drive['PercentUsed'] }}" class="progress-bar {{ $drive['PercentUsed'] > 90 ? 'bg-danger' : '' }}" role="progressbar" style="width: {{ $drive['PercentUsed'] }}%"></div>
                                            </div>
                                            <small class="text-muted">
                                                {{ __(':free free of :total', ['free' => \App\Support\Bytes::format($drive['SizeRemaining']), 'total' => \App\Support\Bytes::format($drive['Size'])]) }}
                                            </small>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($hasUpdates)
                <div aria-labelledby="updates-tab" class="tab-pane fade" id="updates-tab-pane" role="tabpanel" tabindex="0">
                    @if (count($selectedDevice->updates) > 0)
                        <h5>{{ __('Operating system') }}</h5>
                        <ul class="list-group mb-3">
                            @foreach ($selectedDevice->updates as $update)
                                <li class="list-group-item">{{ $update['Title'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if (count($selectedDevice->apps_packages_updates) > 0)
                        <h5>{{ __('Applications') }}</h5>
                        <ul class="list-group">
                            @foreach ($selectedDevice->apps_packages_updates as $appUpdate)
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>{{ $appUpdate['Id'] }}</span>
                                    <x-badge color="primary" variant="subtle">{{ $appUpdate['Version'] }}</x-badge>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            @if (count($selectedDevice->networks) > 0)
                <div aria-labelledby="networks-tab" class="tab-pane fade" id="networks-tab-pane" role="tabpanel" tabindex="0">
                    <ul class="list-group">
                        @foreach ($selectedDevice->networks as $network)
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <span class="fw-semibold">{{ $network->Name }}</span>
                                    <x-badge color="{{ $network->Status === 'Up' ? 'success' : 'secondary' }}" variant="subtle">{{ $network->Status }}</x-badge>
                                </div>
                                @foreach ($network->IPAddresses as $ipAddress)
                                    <div class="small text-muted">{{ $ipAddress }}</div>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (count($services) > 0)
                <div aria-labelledby="services-tab" class="tab-pane fade" id="services-tab-pane" role="tabpanel" tabindex="0" x-data="{ search: '' }">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <input class="form-control form-control-sm" placeholder="{{ __('Search') }}" style="max-width: 20rem;" type="search" x-model="search">
                        <span class="small text-muted ms-auto">{{ __(':running running, :other not running', ['running' => count($services) - $failedServices, 'other' => $failedServices]) }}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Service') }}</th>
                                    <th class="d-none d-md-table-cell">{{ __('Description') }}</th>
                                    <th class="text-end">{{ __('State') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($services as $service)
                                    @php
                                        $state = $service['State'] ?? 'unknown';
                                        $search = strtolower(($service['Name'] ?? '') . ' ' . ($service['DisplayName'] ?? ''));
                                    @endphp
                                    <tr wire:key="service-{{ $loop->index }}" x-show="!search || @js($search).includes(search.toLowerCase())">
                                        <td class="text-break">
                                            <span class="fw-semibold">{{ $service['Name'] ?? '' }}</span>
                                            <div class="small text-muted d-md-none">{{ $service['DisplayName'] ?? '' }}</div>
                                        </td>
                                        <td class="d-none d-md-table-cell text-muted">{{ $service['DisplayName'] ?? '' }}</td>
                                        <td class="text-end text-nowrap">
                                            <x-badge :color="$state === 'running' ? 'success' : ($state === 'failed' ? 'danger' : 'warning')" variant="subtle">
                                                {{ __(ucfirst($state)) }}
                                            </x-badge>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($docker !== null)
                <div aria-labelledby="docker-tab" class="tab-pane fade" id="docker-tab-pane" role="tabpanel" tabindex="0">
                    @if ($docker['error'])
                        <div class="alert alert-warning mb-3" role="alert">
                            <i class="fab fa-docker me-2"></i>{{ __('Docker is installed but the agent cannot read the containers: :error', ['error' => $docker['error']]) }}
                        </div>
                    @endif
                    @if (count($docker['containers']) > 0)
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ __('Container') }}</th>
                                        <th class="d-none d-md-table-cell">{{ __('Image') }}</th>
                                        <th class="d-none d-lg-table-cell">{{ __('Ports') }}</th>
                                        <th class="text-end">{{ __('State') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (collect($docker['containers'])->sortBy(fn ($c) => [($c['State'] ?? '') === 'running' ? 1 : 0, $c['Name'] ?? '']) as $container)
                                        @php
                                            $state = $container['State'] ?? 'unknown';
                                            $color = match (true) {
                                                $state === 'running' && ($container['Health'] ?? null) === 'unhealthy' => 'danger',
                                                $state === 'running' => 'success',
                                                in_array($state, ['restarting', 'paused'], true) => 'warning',
                                                $state === 'dead' => 'danger',
                                                default => 'secondary',
                                            };
                                        @endphp
                                        <tr wire:key="container-{{ $loop->index }}">
                                            <td class="text-break">
                                                <span class="fw-semibold">{{ $container['Name'] ?? '' }}</span>
                                                <div class="small text-muted d-md-none">{{ $container['Image'] ?? '' }}</div>
                                            </td>
                                            <td class="d-none d-md-table-cell text-muted text-break">{{ $container['Image'] ?? '' }}</td>
                                            <td class="d-none d-lg-table-cell small text-muted text-break">{{ $container['Ports'] ?? '' }}</td>
                                            <td class="text-end">
                                                <x-badge :color="$color" variant="subtle">{{ ($container['Health'] ?? null) === 'unhealthy' ? __('Unhealthy') : __(ucfirst($state)) }}</x-badge>
                                                <div class="small text-muted text-nowrap">{{ $container['Status'] ?? '' }}</div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @elseif (! $docker['error'])
                        <p class="text-muted mb-0">{{ __('No containers.') }}</p>
                    @endif
                </div>
            @endif

            @if ($diskHealth !== null)
                <div aria-labelledby="health-tab" class="tab-pane fade" id="health-tab-pane" role="tabpanel" tabindex="0">
                    @if ($diskHealth['error'])
                        <div class="alert alert-warning mb-3" role="alert">
                            <i class="fas fa-heartbeat me-2"></i>{{ $diskHealth['error'] }}
                        </div>
                    @endif
                    @if (count($diskHealth['disks']) > 0)
                        <div class="row g-3">
                            @foreach ($diskHealth['disks'] as $disk)
                                @php
                                    $health = $disk['Health'] ?? 'unknown';
                                    $values = array_filter([
                                        __('Temperature') => isset($disk['Temperature']) ? $disk['Temperature'] . ' °C' : null,
                                        __('Power on') => isset($disk['PowerOnHours']) ? __(':hours h (:days days)', ['hours' => number_format($disk['PowerOnHours'], 0, ',', ' '), 'days' => intdiv((int) $disk['PowerOnHours'], 24)]) : null,
                                        __('Wear') => isset($disk['WearPercent']) ? $disk['WearPercent'] . ' %' : null,
                                        __('Reallocated sectors') => $disk['Reallocated'] ?? null,
                                        __('Pending sectors') => $disk['Pending'] ?? null,
                                        __('Media errors') => $disk['MediaErrors'] ?? null,
                                    ], fn ($value) => $value !== null);
                                @endphp
                                <div class="col-12 col-md-6" wire:key="disk-{{ $loop->index }}">
                                    <div class="card h-100">
                                        <div class="card-body">
                                            <div class="d-flex align-items-start gap-3">
                                                <i class="fas {{ ($disk['MediaType'] ?? '') === 'HDD' ? 'fa-hdd' : 'fa-memory' }} fa-2x text-muted"></i>
                                                <div class="flex-grow-1 min-w-0">
                                                    <div class="d-flex justify-content-between gap-2">
                                                        <span class="fw-semibold text-break">{{ $disk['Model'] ?? $disk['Device'] }}</span>
                                                        <x-badge class="align-self-start flex-shrink-0" :color="match ($health) { 'passed' => 'success', 'failed' => 'danger', 'warning' => 'warning', default => 'secondary' }" variant="subtle">
                                                            {{ match ($health) { 'passed' => __('Healthy'), 'failed' => __('Failing'), 'warning' => __('Warning'), default => __('Unknown') } }}
                                                        </x-badge>
                                                    </div>
                                                    <div class="small text-muted text-break">
                                                        {{ collect([$disk['Device'] ?? null, $disk['MediaType'] ?? null, $disk['Protocol'] ?? null, isset($disk['Size']) ? \App\Support\Bytes::format($disk['Size']) : null, $disk['Serial'] ?? null])->filter()->implode(' · ') }}
                                                    </div>
                                                    @if (! empty($disk['Standby']))
                                                        <div class="small text-muted mt-1"><i class="fas fa-moon me-1"></i>{{ __('Disk is asleep, showing the last known values.') }}</div>
                                                    @endif
                                                    @if ($values)
                                                        <dl class="row small mb-0 mt-2">
                                                            @foreach ($values as $label => $value)
                                                                <dt class="col-6 fw-normal text-muted">{{ $label }}</dt>
                                                                <dd class="col-6 mb-1 text-end">{{ $value }}</dd>
                                                            @endforeach
                                                        </dl>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @elseif (! $diskHealth['error'])
                        <p class="text-muted mb-0">{{ __('No disks with S.M.A.R.T. support found.') }}</p>
                    @endif
                    <p class="small text-muted mt-3 mb-0">{{ __('Checked by the agent every hour; sleeping disks are not woken up.') }}</p>
                </div>
            @endif
        </div>
    </div>
</div>
