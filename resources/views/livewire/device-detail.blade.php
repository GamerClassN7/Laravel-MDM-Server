<div>
    <div class="d-flex justify-content-between">
        <h2 class="offcanvas-title" id="offcanvasRightLabel" title="{{ $selectedDevice->updated_at->diffForHumans() }}">
            @if ($editMode)
                <div class="row g-3 align-items-center">
                    <div class="col-auto">
                        <input class="form-control" id="friendlyName" type="text" wire:model="friendlyName">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary" type="submit" wire:click="saveFriendlyName">{{ __('save') }}</button>
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
                    $power = $selectedDevice->data->machine->Battery ?? [];
                @endphp
                {{-- <i class="bi bi-wifi-off"></i>
                <i class="bi bi-bluetooth"></i> --}}
                @if ($power != [])
                    {{-- @if ($power == [])
                        <i class="bi bi-battery-charging"></i>
                    @else --}}
                    @if ($power < 20)
                        <i class="bi bi-battery text-danger"></i>
                    @elseif($power < 85)
                        <i class="bi bi-battery-half"></i>
                    @else
                        <i class="bi bi-battery-full"></i>
                    @endif
                    {{-- @endif --}}
                    {{ $power }} %
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

    @if (!empty($selectedDevice->data))
        @livewire('device-alerts', ['selectedDeviceId' => $selectedDevice->id], key('device-alerts' . $selectedDevice->id))
    @endif
    @livewire('device-commands', ['selectedDeviceId' => $selectedDevice->id], key('device-commands' . $selectedDevice->id))

    @if (!empty($selectedDevice->drives))
        <h4>{{ __('Drives') }}</h4>
        <div class="d-flex flex-wrap justify-content-between">
            @foreach ($selectedDevice->drives as $drive)
                <div class="me-3 d-flex">
                    @if ($drive['DriveType'] == 5)
                        <i class="bi bi-disc" style="font-size: 3rem;"></i>
                    @else
                        <i class="bi bi-device-hdd" style="font-size: 3rem;"></i>
                    @endif
                    <div style="width:180px">
                        {{ $drive['FriendlyName'] ?? '' }} ({{ $drive['DriveLetter'] }})
                        @if (isset($drive['Size']) && isset($drive['SizeRemaining']))
                            <div class="progress">
                                <div aria-valuemax="100" aria-valuemin="0" aria-valuenow="{{ $drive['PercentUsed'] }}" class="progress-bar {{ $drive['PercentUsed'] > 90 ? 'bg-danger' : '' }}" role="progressbar" style="width: {{ $drive['PercentUsed'] ?? 0 }}%"></div>
                            </div>
                            {{ round($drive['SizeRemaining'] / 1024 / 1024 / 1024) }} GB free of {{ round($drive['Size'] / 1024 / 1024 / 1024) }} GB
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($selectedDevice->updates != [] && count($selectedDevice->updates) > 0)
        <h4>{{ __('Updates.OS') }}</h4>
        <ul>
            @foreach ((array) $selectedDevice->updates as $update)
                <li>{{ $update->Title }}</li>
            @endforeach
        </ul>
    @endif
    @if ($selectedDevice->apps_packages_updates != [] && count($selectedDevice->apps_packages_updates) > 0)
        <h4>{{ __('Updates') }}</h4>
        <ul>
            @foreach ((array) $selectedDevice->apps_packages_updates as $app_update)
                <li>{{ $app_update->Id }} ({{ $app_update->Version }})</li>
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
