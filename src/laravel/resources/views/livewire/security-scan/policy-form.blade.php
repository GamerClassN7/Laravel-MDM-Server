@use('App\Support\CompliancePolicies')
@use('App\Support\SecurityRules')
{{-- A compliance policy in JSON beside a short reference, checked while typing and tried on a device. --}}
<form wire:submit="save">
    <div class="row g-4">
        <div class="col-12 col-lg-7">
            @if ($readOnly)
                <div class="alert alert-info small py-2">{{ __('A policy of the feed: switch it off in the list, or copy it to change it.') }}</div>
            @endif
            <div x-init="$nextTick(() => $el.querySelector('.ace-editor')?.env?.editor?.setOptions({ minLines: 14, maxLines: 28, showPrintMargin: false, readOnly: @js($readOnly) }))">
                <x-form::ace id="policy-json" label="{{ __('Policy (JSON)') }}" language="json" theme="tomorrow_night" wire:model.live.debounce.500ms="json" />
            </div>
            @error('json') <div class="small text-danger mt-1">{{ $message }}</div> @enderror
            @if ($problems !== [])
                <ul class="small text-danger mt-2 mb-0 ps-3">
                    @foreach (array_slice($problems, 0, 20) as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            @else
                <div class="small text-success mt-2"><i class="fas fa-check me-1"></i>{{ __('The policy is valid.') }}</div>
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
                        <div class="small text-muted">{{ __('The policy is not for the platform of :device.', ['device' => $previewDevice->displayName]) }}</div>
                    @else
                        <table class="table table-sm small mb-0">
                            <tbody>
                                @foreach ($preview as $id => $result)
                                    <tr>
                                        <td class="text-nowrap"><x-badge :color="CompliancePolicies::STATUS_COLORS[$result['status']]" size="sm" variant="subtle">{{ __(CompliancePolicies::STATUS_LABELS[$result['status']]) }}</x-badge></td>
                                        <td class="font-monospace text-nowrap">{{ $id }}</td>
                                        <td class="text-break text-muted">{{ $result['message'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif
            </div>
        </div>

        <div class="col-12 col-lg-5 small">
            <div class="fw-medium mb-1">{{ __('Policy keys') }}</div>
            <ul class="ps-3 text-muted">
                <li><code>key</code>, <code>name</code>, <code>description</code>, <code>version</code></li>
                <li><code>platform</code>: {{ implode(', ', SecurityRules::PLATFORMS) }}</li>
                <li><code>source</code>: {{ __('the list the checks look at (a check can name its own)') }}</li>
                <li><code>applies</code>, <code>manual</code>: {{ __('conditions for every check (optional)') }}</li>
                <li><code>checks</code>: {{ __('a list of checks') }}</li>
            </ul>
            <div class="fw-medium mb-1">{{ __('Check keys') }}</div>
            <ul class="ps-3 text-muted">
                <li><code>id</code> ({{ __('e.g.') }} <code>Sql.XpCmdshellDisabled</code>), <code>name</code>, <code>reference</code>, <code>description</code>, <code>remediation</code></li>
                <li><code>severity</code>: {{ implode(', ', array_keys(SecurityRules::SEVERITIES)) }}</li>
                <li><code>applies</code>: {{ __('when false: Not applicable') }}</li>
                <li><code>manual</code>: {{ __('when true: Manual') }}</li>
                <li><code>fail</code>, <code>warn</code>: {{ __('when true: Fail, Warn; otherwise Pass') }}</li>
                <li><code>requires</code>: {{ __('fields without which it is Manual (default: the fields of fail and warn)') }}</li>
                <li><code>message</code>: {{ __('with {Field} placeholders') }}</li>
            </ul>
            <div class="text-muted mb-3">{{ __('Conditions and operators are the ones of the detection rules. Each item of the source is judged; the check takes the worst status. Without items it is Not applicable.') }}</div>
            <div class="fw-medium mb-1">{{ __('Sources and their fields') }}</div>
            <table class="table table-sm small mb-0">
                <tbody>
                    @foreach (CompliancePolicies::sources() as $source)
                        <tr>
                            <td class="text-nowrap"><code>{{ $source }}</code></td>
                            <td class="text-muted">{{ implode(', ', SecurityRules::SOURCES[$source]['fields']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 border-top pt-3 mt-4">
        @if ($policyId && ! $readOnly)
            <button class="btn btn-link text-danger px-0 me-auto" type="button" wire:click="delete" wire:confirm="{{ __('Delete this policy and its results?') }}">{{ __('Delete policy') }}</button>
        @else
            <span class="me-auto"></span>
        @endif
        <button class="btn btn-light" type="button" wire:click="$dispatch('closeModal')">{{ $readOnly ? __('Close') : __('Cancel') }}</button>
        @unless ($readOnly)
            <button class="btn btn-primary" type="submit" @disabled($problems !== [])>{{ __('Save') }}</button>
        @endunless
    </div>
</form>
