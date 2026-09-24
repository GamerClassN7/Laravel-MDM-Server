<div>
    <div class="container-xl">
        <div class="page-header">
            <h1 class="hide-mobile">{{ __('Devices') }}</h1>
            <button class="btn btn-primary" type="button" wire:click.prevent="$set('addDevice', true)">
                <i class="fas fa-plus me-2"></i>{{ __('Add device') }}
            </button>
        </div>
    </div>

    <div class="container-xl">
        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="list-group">
                    @forelse ($devices as $device)
                        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ isset($selectedDevice) && $device->id == $selectedDevice->id ? 'active' : '' }}" href="#" wire:click.prevent="selectDevice({{ $device->id }})">
                            <span>
                                <i class="fas fa-desktop me-2"></i>{{ $device->DisplayName }}
                            </span>
                            @if ($device->offline)
                                <x-badge color="secondary" size="sm" variant="subtle">{{ __('Offline') }}</x-badge>
                            @else
                                <x-badge color="success" size="sm" variant="subtle">{{ __('Online') }}</x-badge>
                            @endif
                        </a>
                    @empty
                        <div class="list-group-item text-muted">{{ __('No devices enrolled yet.') }}</div>
                    @endforelse
                </div>
            </div>

            <div class="col-12 col-lg-8">
                @if ($addDevice)
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">{{ __('Enrol new device') }}</h5>
                            <p class="text-muted mb-2">{{ __('Enter this code in the agent to register the device.') }}</p>
                            <h2 class="display-5 fw-semibold">{{ $enrollmentCode }}</h2>
                            <p class="mb-0 text-muted">
                                <i class="far fa-clock me-1"></i>{{ __('Expires') }} {{ $enrollmentCodeExpiration->diffForHumans() }} ({{ $enrollmentCodeExpiration }})
                            </p>
                        </div>
                    </div>
                @elseif (isset($selectedDevice))
                    @livewire('device-detail', ['selectedDeviceId' => $selectedDevice->id], key($selectedDevice->id))
                @endif
            </div>
        </div>
    </div>
</div>
