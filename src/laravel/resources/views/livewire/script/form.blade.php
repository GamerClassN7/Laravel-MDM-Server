<form wire:submit="save" x-data="{ indent(event) { const el = event.target; const start = el.selectionStart; el.setRangeText('    ', start, el.selectionEnd, 'end'); el.dispatchEvent(new Event('input')); } }">
    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label class="form-label" for="script-name">{{ __('Name') }}</label>
            <input class="form-control @error('name') is-invalid @enderror" id="script-name" type="text" wire:model="name">
            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="script-platform">{{ __('Platform') }}</label>
            <select class="form-select" id="script-platform" wire:model="platform">
                @foreach (\App\Models\Script::PLATFORMS as $value => $label)
                    <option value="{{ $value }}">{{ __($label) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="script-timeout">{{ __('Timeout (s)') }}</label>
            <input class="form-control @error('timeout') is-invalid @enderror" id="script-timeout" max="{{ \App\Models\Script::MAX_TIMEOUT }}" min="5" type="number" wire:model="timeout">
            @error('timeout') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-12">
            <label class="form-label" for="script-description">{{ __('Description') }}</label>
            <input class="form-control" id="script-description" type="text" wire:model="description">
        </div>
        <div class="col-12">
            <label class="form-label" for="script-detection">{{ __('Detection script') }}</label>
            <textarea class="form-control font-monospace @error('detection') is-invalid @enderror" id="script-detection" placeholder="# exit 0 = compliant, exit 1 = run the remediation" rows="12" spellcheck="false" wire:model="detection" x-on:keydown.tab.prevent="indent($event)"></textarea>
            @error('detection') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-12">
            <label class="form-label" for="script-remediation">{{ __('Remediation script') }} <span class="text-muted small">({{ __('optional') }})</span></label>
            <textarea class="form-control font-monospace" id="script-remediation" rows="10" spellcheck="false" wire:model="remediation" x-on:keydown.tab.prevent="indent($event)"></textarea>
            <div class="form-text">
                {{ __('Runs when the detection exits with 1, then the detection runs again. Scripts run as SYSTEM / root, without network access, output is kept up to 16 kB.') }}
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4">
        <div class="small text-muted">
            @if ($script)
                {{ __('Version') }} {{ $script->version }} · {{ __('Fingerprint') }} <code class="text-break">{{ $script->fingerprint }}</code>
                <div>{{ __('Saving changed code creates a new version with a new fingerprint.') }}</div>
            @endif
        </div>
        <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
    </div>
</form>
