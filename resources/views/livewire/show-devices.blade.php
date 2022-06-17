<div>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col col-lg-4">
                <ul class="list-group">
                    @foreach ($devices as $device)
                        <a href="#" wire:click.prevent="selectDevice({{ $device->id }})" aria-current="true" class="list-group-item list-group-item-action {{ isset($selectedDevice) && $device->id == $selectedDevice->id ? 'active' : '' }}">{{ $device->name }}</a>
                    @endforeach
                </ul>
                <div class="d-grid gap-2 mb-3">
                    <button wire:click.prevent="$set('addDevice', true)" class="btn btn-primary mt-2 btn-block" type="button">
                        <i class="bi bi-plus-square"></i>
                        {{ __('add') }}
                    </button>
                </div>
            </div>


            @if ($addDevice == true)
                <div class="col col-lg-8">
                    <h5>{{ __('AddNewDevice') }}</h5>
                    <h2>{{ $enrollmentCode }}</h2>
                    <p>{{ $enrollmentCodeExpiration }} ({{ $enrollmentCodeExpiration->diffForHumans() }})
                    <p>
                </div>
            @elseif (isset($selectedDevice))
                @php
                    $updates = [];
                    if (property_exists(json_decode($selectedDevice->data)->machine, 'updates')) {
                        $updates = (array) json_decode($selectedDevice->data)->machine->updates;
                    }
                    
                    $networks = [];
                    if (property_exists(json_decode($selectedDevice->data)->machine, 'addresses')) {
                        $networks = (array) json_decode($selectedDevice->data)->machine->addresses;
                    }
                @endphp
                <div class="col col-lg-8">
                    <div class="d-flex justify-content-between">
                        <h2 class="offcanvas-title" id="offcanvasRightLabel" title="{{ $selectedDevice->updated_at->diffForHumans() }}">
                            @if ($updates != [] && count($updates) > 1)
                                <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                            @else
                                <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                            @endif
                            {{ $selectedDevice->name }}
                        </h2>
                        <h3 class="offcanvas-title" id="offcanvasRightLabel">
                            {{-- <i class="bi bi-wifi-off"></i> --}}
                            {{-- <i class="bi bi-bluetooth"></i> --}}
                            @if (isset(json_decode($selectedDevice->data)->machine->power))
                                @php
                                    $power = json_decode($selectedDevice->data)->machine->power;
                                @endphp
                                @if ($power->charging_Status == 'AC')
                                    <i class="bi bi-battery-charging"></i>
                                @else
                                    @if ($power->battery < 20)
                                        <i class="bi bi-battery text-danger"></i>
                                    @elseif($power->battery < 60)
                                        <i class="bi bi-battery-half"></i>
                                    @else
                                        <i class="bi bi-battery-full"></i>
                                    @endif
                                @endif
                                {{ $power->battery }} %
                            @else
                                <i class="bi bi-plug"></i>
                            @endif
                        </h3>
                    </div>

                    @if (!empty($selectedDevice->NiceUptime))
                        <p>{{ $selectedDevice->NiceUptime }}</p>
                    @endif

                    @if (isset(json_decode($selectedDevice->data)->settings->timeout) && json_decode($selectedDevice->data)->settings->timeout < $selectedDevice->updated_at->diffInSeconds())
                        <div class="alert alert-secondary" role="alert">
                            {{ __('Device is offline!') }}
                        </div>
                    @endif

                    @if (isset(json_decode($selectedDevice->data)->machine->restart_pending) && filter_var(json_decode($selectedDevice->data)->machine->restart_pending, FILTER_VALIDATE_BOOLEAN) == true)
                        <div class="alert alert-warning" role="alert">
                            {{ __('Device is in restart pending state!') }}
                        </div>
                    @endif


                    @livewire('device-commands', ['selectedDeviceId' => $selectedDevice->id], key($selectedDevice->id))

                    @if (!empty($selectedDevice->drives))
                        <h4>{{ __('Drives') }}</h4>
                        <div class="d-flex flex-wrap justify-content-between">
                            @foreach ($selectedDevice->drives as $drive)
                                <div class="me-3 d-flex">
                                    <i style="font-size: 3rem;" class="bi bi-device-hdd"></i>
                                    <div style="width:180px">
                                        {{ $drive['VolumeLabel'] ?? '' }} ({{ $drive['Name'] }})
                                        @if (isset($drive['TotalSize']) && isset($drive['AvailableFreeSpace']))
                                            @php
                                                $usedSpace = (int) $drive['TotalSize'] - (int) $drive['AvailableFreeSpace'];
                                                $usedPercent = $usedSpace / ((int) $drive['TotalSize'] / 100);
                                            @endphp
                                            <div class="progress">
                                                <div class="progress-bar {{ $usedPercent > 90 ? 'bg-danger' : '' }}" role="progressbar" style="width: {{ round($usedPercent) ?? 0 }}%" aria-valuenow="{{ round($usedPercent) }}" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                            {{ round($drive['AvailableFreeSpace'] / 1024 / 1024 / 1024) }} GB free of {{ round($drive['TotalSize'] / 1024 / 1024 / 1024) }} GB
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($updates != [] && count($updates) > 0)
                        <h4>{{ __('Updates') }}</h4>
                        <ul>
                            @foreach ((array) $updates as $update)
                                <li>{{ $update }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($networks != [] && count($networks) > 0)
                        <h4>{{ __('Networks') }}</h4>
                        <ul>
                            @foreach ((array) $networks as $network)
                                <li>{{ $network }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
