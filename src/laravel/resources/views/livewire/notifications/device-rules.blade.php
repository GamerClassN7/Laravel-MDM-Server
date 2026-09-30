<div>
    <div class="list-group list-group-flush">
        @foreach (\App\Models\AlertRule::TYPES as $type => $definition)
            <div class="list-group-item px-0" wire:key="device-rule-{{ $type }}">
                <div class="d-flex align-items-center gap-3">
                    <i class="{{ $definition['icon'] }} fa-fw text-body-secondary"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold">{{ __($definition['label']) }}</div>
                        <div class="small text-muted">{{ __($definition['description']) }}</div>
                    </div>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" wire:model.live="settings.{{ $type }}.enabled" aria-label="{{ __($definition['label']) }}">
                    </div>
                </div>
                @if ($settings[$type]['enabled'] && ($definition['threshold'] !== null || $definition['minutes'] !== null))
                    <div class="d-flex flex-wrap gap-3 mt-2 ms-5 small">
                        @if ($definition['threshold'] !== null)
                            @php $gb = \App\Models\AlertRule::usesUnit($type) && $settings[$type]['unit'] === 'gb'; @endphp
                            @if ($gb)
                                <label class="d-flex align-items-center gap-2">
                                    {{ $type === 'memory' ? __('Free memory below') : __('Free space below') }}
                                    <input class="form-control form-control-sm" max="{{ \App\Models\AlertRule::MAX_LIMIT_GB }}" min="0.1" step="0.1" style="width: 6rem" type="number" wire:model.live.debounce.600ms="settings.{{ $type }}.limit_gb">
                                    GB
                                </label>
                            @else
                                <label class="d-flex align-items-center gap-2">
                                    {{ __('Above') }}
                                    <input class="form-range" max="99" min="1" style="width: 10rem" type="range" wire:model.live.debounce.400ms="settings.{{ $type }}.threshold">
                                    <span class="fw-semibold">{{ $settings[$type]['threshold'] }} %</span>
                                </label>
                            @endif
                            @if (\App\Models\AlertRule::usesUnit($type))
                                <div class="btn-group btn-group-sm" role="group" aria-label="{{ __('Unit') }}">
                                    @foreach (\App\Models\AlertRule::UNITS as $unitValue => $unitLabel)
                                        <input autocomplete="off" class="btn-check" id="device-rule-{{ $type }}-{{ $unitValue }}" type="radio" value="{{ $unitValue }}" wire:model.live="settings.{{ $type }}.unit">
                                        <label class="btn btn-outline-secondary py-0" for="device-rule-{{ $type }}-{{ $unitValue }}">{{ $unitLabel }}</label>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                        @if ($definition['minutes'] !== null)
                            <label class="d-flex align-items-center gap-2">
                                {{ $type === 'status' ? __('for at least') : __('average over') }}
                                <input class="form-control form-control-sm" max="{{ \App\Models\AlertRule::MAX_MINUTES }}" min="1" style="width: 5rem" type="number" wire:model.live.debounce.600ms="settings.{{ $type }}.minutes">
                                {{ __('min') }}
                            </label>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    @if ($broader->isNotEmpty())
        <div class="small text-muted mt-3">
            <div class="fw-semibold mb-1">{{ __('Also alerting for this device') }}</div>
            @foreach ($broader as $rule)
                <div class="d-flex flex-wrap align-items-center gap-1"><i class="{{ $rule->icon }} fa-fw"></i>{{ $rule->label }}: {{ $rule->condition }} · <x-target-summary :target="$rule->target ?? []" />{{ $rule->enabled ? '' : ' ('.__('disabled').')' }}</div>
            @endforeach
        </div>
    @endif
    <div class="small text-muted mt-3">
        {{ __('Sent to the channels on the') }} <a href="{{ route('notifications') }}">{{ __('Notifications') }}</a> {{ __('page') }}.
    </div>
</div>
