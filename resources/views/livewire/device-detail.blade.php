<div>
    @php
        $hasUpdates = count($selectedDevice->updates) > 0 || count($selectedDevice->apps_packages_updates) > 0;
        $power = $selectedDevice->data->machine->Battery ?? null;
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
                                                {{ __(':free GB free of :total GB', ['free' => round($drive['SizeRemaining'] / 1024 / 1024 / 1024), 'total' => round($drive['Size'] / 1024 / 1024 / 1024)]) }}
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
        </div>
    </div>
</div>
