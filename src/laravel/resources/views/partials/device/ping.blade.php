{{-- A ping-only device: who pings it, the last answer, and its address and MAC. --}}
@php
    $relay = $selectedDevice->pingRelay();
    $lastRelay = $selectedDevice->ping_relay_id ? \App\Models\Device::find($selectedDevice->ping_relay_id) : null;
@endphp
<div class="card mt-3">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <span class="icon-tile {{ $selectedDevice->offline ? '' : 'bg-success-subtle text-success-emphasis' }}"><i class="fas fa-network-wired"></i></span>
            <div class="me-auto">
                <div class="fw-semibold">{{ __('Ping only') }}</div>
                <div class="small text-muted">
                    @if ($relay)
                        {{ __('Pinged every 30 s by :relay', ['relay' => $relay->displayName]) }}
                        @if (! $selectedDevice->offline && $selectedDevice->ping_rtt !== null)
                            · {{ __('answered in :rtt ms', ['rtt' => $selectedDevice->ping_rtt]) }}
                        @endif
                    @else
                        {{ __('No agent pings it: it needs an online agent 1.10.0+ in the same network that does not run on a battery.') }}
                    @endif
                    @if ($selectedDevice->offline && $selectedDevice->last_seen_at)
                        · {{ __('last answer :time', ['time' => $selectedDevice->last_seen_at->diffForHumans()]) }}{{ $lastRelay ? ' ('.$lastRelay->displayName.')' : '' }}
                    @endif
                </div>
            </div>
        </div>

        <form class="row g-2 align-items-end" wire:submit="savePing" style="max-width: 44rem">
            <div class="col-7 col-md-5">
                <label class="form-label small" for="ping-address-{{ $selectedDevice->id }}">{{ __('IPv4 address') }}</label>
                <input class="form-control form-control-sm font-monospace" id="ping-address-{{ $selectedDevice->id }}" type="text" wire:model="pingAddress">
            </div>
            <div class="col-5 col-md-2">
                <label class="form-label small" for="ping-prefix-{{ $selectedDevice->id }}">{{ __('Prefix') }}</label>
                <input class="form-control form-control-sm" id="ping-prefix-{{ $selectedDevice->id }}" max="30" min="8" type="number" wire:model="pingPrefix">
            </div>
            <div class="col-8 col-md-3">
                <label class="form-label small" for="ping-mac-{{ $selectedDevice->id }}">{{ __('MAC address') }}</label>
                <input class="form-control form-control-sm font-monospace" id="ping-mac-{{ $selectedDevice->id }}" placeholder="AA:BB:CC:DD:EE:FF" type="text" wire:model="pingMac">
            </div>
            <div class="col-4 col-md-2">
                <button class="btn btn-sm btn-light w-100" type="submit">{{ __('Save') }}</button>
            </div>
            @error('ping') <div class="col-12 small text-danger">{{ $message }}</div> @enderror
        </form>
    </div>
</div>
