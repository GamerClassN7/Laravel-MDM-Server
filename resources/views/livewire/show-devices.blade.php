<div>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col col-lg-4">
                <ul class="list-group">
                    @foreach ($devices as $device)
                        <a href="#" wire:click.prevent="selectDevice({{ $device->id }})" aria-current="true" class="list-group-item list-group-item-action d-flex justify-content-between {{ isset($selectedDevice) && $device->id == $selectedDevice->id ? 'active' : '' }}">
                            {{ $device->DisplayName }}
                            @if ($device->offline)
                                <i class="bi bi-exclamation-triangle-fill text-dark"></i>
                            @endif
                        </a>
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
                <div class="col-12 col-lg-8">
                    <h5>{{ __('AddNewDevice') }}</h5>
                    <h2>{{ $enrollmentCode }}</h2>
                    <p>{{ $enrollmentCodeExpiration }} ({{ $enrollmentCodeExpiration->diffForHumans() }})
                    <p>
                </div>
            @elseif (isset($selectedDevice))
                <div class="col-12 col-lg-8">
                    @livewire('device-detail', ['selectedDeviceId' => $selectedDevice->id], key($selectedDevice->id))
                </div>
            @endif
        </div>
    </div>
</div>
