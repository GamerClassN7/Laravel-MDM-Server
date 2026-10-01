<form wire:submit="save">
    <div class="row g-3">
        <div class="col-8">
            <label class="form-label" for="ping-settings-address">{{ __('IPv4 address') }}</label>
            <input class="form-control font-monospace @error('address') is-invalid @enderror" id="ping-settings-address" placeholder="192.168.1.50" type="text" wire:model="address">
        </div>
        <div class="col-4">
            <label class="form-label" for="ping-settings-prefix">{{ __('Prefix') }}</label>
            <div class="input-group">
                <span class="input-group-text">/</span>
                <input class="form-control" id="ping-settings-prefix" max="30" min="8" type="number" wire:model="prefix">
            </div>
        </div>
        <div class="col-12">
            <label class="form-label" for="ping-settings-mac">{{ __('MAC address') }} <span class="text-muted">({{ __('for Wake-on-LAN') }})</span></label>
            <input class="form-control font-monospace" id="ping-settings-mac" placeholder="AA:BB:CC:DD:EE:FF" type="text" wire:model="mac">
        </div>
        @error('address') <div class="col-12 small text-danger">{{ $message }}</div> @enderror
        <div class="col-12 small text-muted">{{ __('The prefix tells which agents share its network: they ping it and wake it. A new address starts the history over (offline until it answers).') }}</div>
    </div>
    <div class="d-flex justify-content-end gap-2 border-top pt-3 mt-4">
        <button class="btn btn-light" type="button" wire:click="$dispatch('closeModal')">{{ __('Cancel') }}</button>
        <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
    </div>
</form>
