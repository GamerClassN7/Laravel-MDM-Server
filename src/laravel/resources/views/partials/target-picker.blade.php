{{-- Which devices: all, the ones with any of the tags, or picked ones. Binds targetAll, targetTags
     and targetDevices of the Livewire component. --}}
@php
    $pickerTags = \App\Models\Device::allTags();
    $pickerDevices = \App\Models\Device::query()->get()->sortBy(fn ($device) => mb_strtolower($device->displayName));
@endphp
<div>
    <div class="form-check form-switch mb-2">
        <input class="form-check-input" id="target-all" type="checkbox" wire:model.live="targetAll">
        <label class="form-check-label" for="target-all">{{ __('All devices, also the ones enrolled later') }}</label>
    </div>
    @unless ($targetAll)
        @if ($pickerTags !== [])
            <div class="mb-2">
                <div class="small text-muted mb-1">{{ __('Devices with any of these tags') }}</div>
                <div class="d-flex flex-wrap gap-2">
                    @foreach ($pickerTags as $pickerTag)
                        <label class="btn btn-sm rounded-pill py-0 {{ in_array($pickerTag, $targetTags, true) ? 'btn-primary' : 'btn-light' }}">
                            <input class="d-none" type="checkbox" value="{{ $pickerTag }}" wire:model.live="targetTags">
                            <i class="fas fa-tag me-1 small"></i>{{ $pickerTag }}
                        </label>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="small text-muted mb-1">{{ __('And these devices') }}</div>
        <div class="list-group" style="max-height: 16rem; overflow-y: auto;">
            @forelse ($pickerDevices as $pickerDevice)
                <label class="list-group-item d-flex align-items-center gap-2" wire:key="target-device-{{ $pickerDevice->id }}">
                    <input class="form-check-input m-0" type="checkbox" value="{{ $pickerDevice->id }}" wire:model.live="targetDevices">
                    <i class="{{ $pickerDevice->typeIcon }} text-body-secondary"></i>
                    <span class="me-auto">{{ $pickerDevice->displayName }}</span>
                    @foreach ($pickerDevice->tagList as $pickerDeviceTag)
                        <span class="badge rounded-pill text-bg-light fw-normal">{{ $pickerDeviceTag }}</span>
                    @endforeach
                </label>
            @empty
                <div class="list-group-item text-muted">{{ __('No devices.') }}</div>
            @endforelse
        </div>
    @endunless
</div>
