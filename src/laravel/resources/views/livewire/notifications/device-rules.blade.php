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
                            <label class="d-flex align-items-center gap-2">
                                {{ __('Above') }}
                                <input class="form-range" max="99" min="1" style="width: 10rem" type="range" wire:model.live.debounce.400ms="settings.{{ $type }}.threshold">
                                <span class="fw-semibold">{{ $settings[$type]['threshold'] }} %</span>
                            </label>
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
                <div><i class="{{ $rule->icon }} fa-fw me-1"></i>{{ $rule->label }}: {{ $rule->condition }} · {{ $rule->targetDescription }}{{ $rule->enabled ? '' : ' ('.__('disabled').')' }}</div>
            @endforeach
        </div>
    @endif
    <div class="small text-muted mt-3">
        {{ __('Sent to the channels on the') }} <a href="{{ route('notifications') }}">{{ __('Notifications') }}</a> {{ __('page') }}.
    </div>
</div>
