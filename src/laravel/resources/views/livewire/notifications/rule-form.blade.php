<form wire:submit="save">
    <label class="form-label">{{ __('Alert when') }}</label>
    <div class="row g-2 mb-3">
        @foreach (\App\Models\AlertRule::TYPES as $value => $definition)
            <div class="col-6 col-md-4">
                <label class="card card-body p-2 h-100 d-flex flex-row gap-2 align-items-start {{ $type === $value ? 'border-primary' : '' }}" style="cursor: pointer">
                    <input class="form-check-input mt-1" type="radio" value="{{ $value }}" wire:model.live="type">
                    <span>
                        <span class="d-block fw-semibold small"><i class="{{ $definition['icon'] }} fa-fw me-1"></i>{{ __($definition['label']) }}</span>
                        <span class="d-block small text-muted">{{ __($definition['description']) }}</span>
                    </span>
                </label>
            </div>
        @endforeach
    </div>

    <div class="row g-3 mb-3">
        @if (\App\Models\AlertRule::usesThreshold($type))
            <div class="col-6">
                <label class="form-label" for="rule-threshold">{{ __('Threshold') }}</label>
                <div class="input-group">
                    <input class="form-control @error('threshold') is-invalid @enderror" id="rule-threshold" max="99" min="1" type="number" wire:model="threshold">
                    <span class="input-group-text">%</span>
                </div>
                @error('threshold') <div class="small text-danger mt-1">{{ $message }}</div> @enderror
            </div>
        @endif
        @if (\App\Models\AlertRule::usesMinutes($type))
            <div class="col-6">
                <label class="form-label" for="rule-minutes">{{ $type === 'status' ? __('Offline for at least') : __('Average over') }}</label>
                <div class="input-group">
                    <input class="form-control @error('minutes') is-invalid @enderror" id="rule-minutes" max="{{ \App\Models\AlertRule::MAX_MINUTES }}" min="1" type="number" wire:model="minutes">
                    <span class="input-group-text">{{ __('min') }}</span>
                </div>
                @error('minutes') <div class="small text-danger mt-1">{{ $message }}</div> @enderror
            </div>
        @endif
    </div>

    <label class="form-label">{{ __('Devices') }}</label>
    @include('partials.target-picker')
    @error('target') <div class="small text-danger mt-1">{{ $message }}</div> @enderror

    <div class="d-flex justify-content-end mt-4">
        <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
    </div>
</form>
