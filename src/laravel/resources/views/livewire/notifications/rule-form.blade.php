{{-- An alert as a sentence: what, when (threshold, unit, minutes) and on which devices. --}}
@php
    $types = \App\Models\AlertRule::TYPES;
    $definition = $types[$type] ?? null;
    $hasUnit = \App\Models\AlertRule::usesUnit($type);
    $gb = $hasUnit && $unit === 'gb';
@endphp
<form wire:submit="save">
    <div class="mb-3">
        <label class="form-label" for="rule-type">{{ __('Alert') }}</label>
        <select class="form-select" id="rule-type" wire:model.live="type">
            @foreach ($types as $value => $option)
                <option value="{{ $value }}">{{ __($option['label']) }}</option>
            @endforeach
        </select>
    </div>

    <div class="rounded bg-body-tertiary p-3 mb-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <i class="{{ $definition['icon'] ?? 'fas fa-bell' }} fa-fw text-body-secondary"></i>
            @switch($type)
                @case('status')
                    <span>{{ __('Offline for at least') }}</span>
                    @break
                @case('cpu')
                    <span>{{ __('Average usage above') }}</span>
                    @break
                @case('memory')
                    <span>{{ $gb ? __('Average free memory below') : __('Average usage above') }}</span>
                    @break
                @case('disk')
                    <span>{{ $gb ? __('A drive with less free space than') : __('A drive fuller than') }}</span>
                    @break
                @default
                    <span>{{ __($definition['description'] ?? '') }}</span>
            @endswitch

            @if (\App\Models\AlertRule::usesThreshold($type))
                <div class="input-group input-group-sm" style="width: auto">
                    @if ($gb)
                        <input class="form-control @error('limitGb') is-invalid @enderror" aria-label="{{ __('Limit') }}" max="{{ \App\Models\AlertRule::MAX_LIMIT_GB }}" min="0.1" step="0.1" style="width: 5.5rem" type="number" wire:model="limitGb">
                    @else
                        <input class="form-control @error('threshold') is-invalid @enderror" aria-label="{{ __('Threshold') }}" max="99" min="1" style="width: 4.5rem" type="number" wire:model="threshold">
                    @endif
                    @if ($hasUnit)
                        @foreach (\App\Models\AlertRule::UNITS as $unitValue => $unitLabel)
                            <input autocomplete="off" class="btn-check" id="rule-unit-{{ $unitValue }}" type="radio" value="{{ $unitValue }}" wire:model.live="unit">
                            <label class="btn btn-outline-secondary" for="rule-unit-{{ $unitValue }}">{{ $unitLabel }}</label>
                        @endforeach
                    @else
                        <span class="input-group-text">%</span>
                    @endif
                </div>
            @endif

            @if (\App\Models\AlertRule::usesMinutes($type))
                @if ($type !== 'status')
                    <span>{{ __('over') }}</span>
                @endif
                <div class="input-group input-group-sm" style="width: auto">
                    <input class="form-control @error('minutes') is-invalid @enderror" aria-label="{{ __('Minutes') }}" max="{{ \App\Models\AlertRule::MAX_MINUTES }}" min="1" style="width: 4.5rem" type="number" wire:model="minutes">
                    <span class="input-group-text">{{ __('min') }}</span>
                </div>
            @endif
        </div>
        @foreach (['threshold', 'limitGb', 'minutes'] as $field)
            @error($field) <div class="small text-danger mt-1">{{ $message }}</div> @enderror
        @endforeach
    </div>

    <label class="form-label">{{ __('Devices') }}</label>
    @include('partials.target-picker')
    @error('target') <div class="small text-danger mt-1">{{ $message }}</div> @enderror

    <div class="d-flex justify-content-end mt-4">
        <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
    </div>
</form>
