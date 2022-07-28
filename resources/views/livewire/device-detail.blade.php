<div>
    <div class="d-flex justify-content-between">
        <h2 class="offcanvas-title" id="offcanvasRightLabel" title="{{ $selectedDevice->updated_at->diffForHumans() }}">
            @if ($editMode)
                <div class="row g-3 align-items-center">
                    <div class="col-auto">
                        <input type="text" id="friendlyName" class="form-control" wire:model="friendlyName">
                    </div>
                    <div class="col-auto">
                        <button type="submit" wire:click="saveFriendlyName" class="btn btn-primary">{{ __('save') }}</button>
                    </div>
                </div>
            @else
                @if ($selectedDevice->updates != [] && count($selectedDevice->updates) > 1)
                    <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                @else
                    <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                @endif
                {{ $selectedDevice->DisplayName }} <i class="bi bi-pencil text-warning" wire:click="$set('editMode', 'true')"></i>
            @endif
        </h2>

        @if (!$selectedDevice->offline)
            <h3 class="offcanvas-title" id="offcanvasRightLabel">
                @php
                    $power = json_decode($selectedDevice->data)->machine->power;
                @endphp

                {{-- <i class="bi bi-wifi-off"></i>
                <i class="bi bi-bluetooth"></i> --}}

                @if ($power != [])
                    @php
                        $charging_status = $power->charging_status ?? $power->charging_Status;
                    @endphp
                    @if (isset($charging_status) || $charging_status == 'AC')
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
        @endif
    </div>
    @if (!$selectedDevice->offline)
        @if (!empty($selectedDevice->lastLogonUser))
            <p class="mb-0"><i class="bi bi-person-fill"></i> {{ $selectedDevice->lastLogonUser }}</p>
        @endif

        @if (!empty($selectedDevice->NiceUptime))
            <p class="mb-0">{{ $selectedDevice->NiceUptime }}</p>
        @endif
    @endif

    @livewire('device-alerts', ['selectedDeviceId' => $selectedDevice->id], key('device-alerts' . $selectedDevice->id))
    @livewire('device-commands', ['selectedDeviceId' => $selectedDevice->id], key('device-commands' . $selectedDevice->id))

    @if (!empty($selectedDevice->drives))
        <h4>{{ __('Drives') }}</h4>
        <div class="d-flex flex-wrap justify-content-between">
            @foreach ($selectedDevice->drives as $drive)
                <div class="me-3 d-flex">
                    @if ($drive['DriveType'] == 5)
                        <i style="font-size: 3rem;" class="bi bi-disc"></i>
                    @else
                        <i style="font-size: 3rem;" class="bi bi-device-hdd"></i>
                    @endif
                    <div style="width:180px">
                        {{ $drive['VolumeLabel'] ?? '' }} ({{ $drive['Name'] }})
                        @if (isset($drive['TotalSize']) && isset($drive['AvailableFreeSpace']))
                            <div class="progress">
                                <div class="progress-bar {{ $drive['PercentUsed'] > 90 ? 'bg-danger' : '' }}" role="progressbar" style="width: {{ $drive['PercentUsed'] ?? 0 }}%" aria-valuenow="{{ $drive['PercentUsed'] }}" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            {{ round($drive['AvailableFreeSpace'] / 1024 / 1024 / 1024) }} GB free of {{ round($drive['TotalSize'] / 1024 / 1024 / 1024) }} GB
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($selectedDevice->updates != [] && count($selectedDevice->updates) > 0)
        <h4>{{ __('Updates') }}</h4>
        <ul>
            @foreach ((array) $selectedDevice->updates as $update)
                <li>{{ $update }}</li>
            @endforeach
        </ul>
    @endif
    @if ($selectedDevice->apps_packages_updates != [] && count($selectedDevice->apps_packages_updates) > 0)
        <h4>{{ __('Updates') }}</h4>
        <ul>
            @foreach ((array) $selectedDevice->apps_packages_updates as $app_update)
                <li>{{ $app_update }}</li>
            @endforeach
        </ul>
    @endif
    @if ($selectedDevice->networks != [] && count($selectedDevice->networks) > 0)
        <h4>{{ __('Networks') }}</h4>
        <ul>
            @foreach ((array) $selectedDevice->networks as $network)
                <li>{{ $network }}</li>
            @endforeach
        </ul>
    @endif
</div>
