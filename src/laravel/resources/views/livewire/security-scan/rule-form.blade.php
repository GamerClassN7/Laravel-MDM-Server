{{-- A rule in JSON beside a short reference, checked while typing and tried on a device. --}}
<form wire:submit="save">
    <div class="row g-4">
        <div class="col-12 col-lg-7">
            @if ($readOnly)
                <div class="alert alert-info small py-2">{{ __('A built-in rule: switch it off on the Rules tab, or copy it to change it.') }}</div>
            @endif
            <textarea class="form-control font-monospace small @error('json') is-invalid @enderror" rows="22" spellcheck="false" wire:model.live.debounce.500ms="json" @readonly($readOnly) aria-label="{{ __('Rule (JSON)') }}"></textarea>
            @error('json') <div class="small text-danger mt-1">{{ $message }}</div> @enderror
            @if ($problems !== [])
                <ul class="small text-danger mt-2 mb-0 ps-3">
                    @foreach ($problems as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            @else
                <div class="small text-success mt-2"><i class="fas fa-check me-1"></i>{{ __('The rule is valid.') }}</div>
            @endif

            <div class="mt-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="small fw-medium text-muted">{{ __('Try on') }}</span>
                    <select class="form-select form-select-sm w-auto" wire:model.live="previewDeviceId" aria-label="{{ __('Device') }}">
                        @forelse ($devices as $device)
                            <option value="{{ $device->id }}">{{ $device->displayName }}</option>
                        @empty
                            <option value="">{{ __('No device with an inventory yet') }}</option>
                        @endforelse
                    </select>
                </div>
                @if ($preview !== null)
                    @if ($preview === [])
                        <div class="small text-muted">{{ __('Nothing found on :device.', ['device' => $previewDevice->displayName]) }}</div>
                    @else
                        <div class="small fw-medium mb-1">{{ trans_choice(':count finding|:count findings', count($preview)) }}</div>
                        <ul class="small mb-0 ps-3" style="max-height: 12rem; overflow: auto">
                            @foreach (array_slice($preview, 0, 50) as $match)
                                <li class="text-break">{{ $match['message'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>
        </div>

        <div class="col-12 col-lg-5 small">
            <div class="fw-medium mb-1">{{ __('Keys') }}</div>
            <ul class="ps-3 text-muted">
                <li><code>key</code>, <code>name</code>, <code>description</code>, <code>remediation</code></li>
                <li><code>severity</code>: {{ implode(', ', array_keys(\App\Support\SecurityRules::SEVERITIES)) }}</li>
                <li><code>platform</code>: {{ implode(', ', \App\Support\SecurityRules::PLATFORMS) }}</li>
                <li><code>source</code>: {{ __('which list it looks at, below') }}</li>
                <li><code>when</code>: <code>{"field": "Name", "op": "contains", "value": "…"}</code>, {{ __('grouped with') }} <code>{"all": […]}</code>, <code>{"any": […]}</code>, <code>{"not": {…}}</code></li>
                <li><code>message</code>: {{ __('with {Field} placeholders') }}</li>
                <li><code>threshold</code>: {{ __('one finding when at least N items match ({count}, {items})') }}</li>
            </ul>
            <div class="fw-medium mb-1">{{ __('Operators') }}</div>
            <div class="text-muted mb-3"><code>{{ implode(' ', \App\Support\SecurityRules::OPERATORS) }}</code><br>{{ __('Texts ignore case; matches is a regular expression.') }}</div>
            <div class="fw-medium mb-1">{{ __('Sources and their fields') }}</div>
            <table class="table table-sm small mb-0">
                <tbody>
                    @foreach (\App\Support\SecurityRules::SOURCES as $source => $definition)
                        <tr>
                            <td class="text-nowrap"><code>{{ $source }}</code></td>
                            <td class="text-muted">{{ implode(', ', $definition['fields']) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td class="text-nowrap text-muted">{{ __('Event types') }}</td>
                        <td class="text-muted">{{ implode(', ', array_keys(\App\Support\SecurityRules::EVENT_TYPES)) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 border-top pt-3 mt-4">
        @if ($ruleId && ! $readOnly)
            <button class="btn btn-link text-danger px-0 me-auto" type="button" wire:click="delete" wire:confirm="{{ __('Delete this rule and its findings?') }}">{{ __('Delete rule') }}</button>
        @else
            <span class="me-auto"></span>
        @endif
        <button class="btn btn-light" type="button" wire:click="$dispatch('closeModal')">{{ $readOnly ? __('Close') : __('Cancel') }}</button>
        @unless ($readOnly)
            <button class="btn btn-primary" type="submit" @disabled($problems !== [])>{{ __('Save') }}</button>
        @endunless
    </div>
</form>
