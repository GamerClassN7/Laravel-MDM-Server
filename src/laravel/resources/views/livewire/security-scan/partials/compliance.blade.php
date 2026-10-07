@use('App\Models\Device')
@use('App\Support\CompliancePolicies')
{{-- Compliance policies: their results over the fleet, or the checks of one policy. --}}
@php
    $bar = function (array $counts) {
        $total = array_sum($counts);
        return $total === 0 ? [] : collect(CompliancePolicies::STATUSES)->keys()
            ->filter(fn ($status) => ($counts[$status] ?? 0) > 0)
            ->map(fn ($status) => ['status' => $status, 'count' => $counts[$status], 'width' => round(100 * $counts[$status] / $total, 2)])->values()->all();
    };
@endphp

@if ($selectedPolicy === null)
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <div class="small text-muted me-auto">{{ __('Policies are benchmarks and baselines (CIS and your own): every check gives each device Pass, Fail, Warn, Manual (cannot be told from what the agent reports) or Not applicable.') }}</div>
        @if ($isAdmin)
            <button class="btn btn-sm btn-light" type="button" wire:click="reevaluate"><i class="fas fa-sync me-1"></i>{{ __('Evaluate again') }}</button>
            <button class="btn btn-sm btn-primary" type="button" wire:click="addPolicy"><i class="fas fa-plus me-1"></i>{{ __('Add policy') }}</button>
        @endif
    </div>
    <div class="card overflow-hidden">
        <div class="list-group list-group-flush">
            @forelse ($policies as $item)
                @php($counts = $policyCounts->get($item->id, []))
                @php($score = CompliancePolicies::score($counts))
                <div class="list-group-item py-3 {{ $item->enabled ? '' : 'opacity-50' }}" wire:key="compliance-policy-{{ $item->id }}">
                    <div class="d-flex flex-wrap align-items-start gap-3">
                        <div class="me-auto min-w-0">
                            <button class="btn btn-link p-0 fw-medium text-start text-decoration-none" type="button" wire:click="$set('policy', {{ $item->id }})">{{ $item->name }}</button>
                            @unless ($item->built_in)<x-badge color="primary" size="sm" variant="subtle">{{ __('Custom') }}</x-badge>@endunless
                            @if ($item->definition['description'] ?? null)
                                <div class="small text-muted">{{ $item->definition['description'] }}</div>
                            @endif
                            <div class="small text-body-tertiary">
                                <span class="font-monospace">{{ $item->key }}</span>
                                · {{ trans_choice(':count check|:count checks', count($item->definition['checks'] ?? [])) }}
                                · {{ $item->platform === 'any' ? __('All platforms') : ucfirst($item->platform) }}
                                @if ($policyDevices->get($item->id)) · {{ trans_choice(':count device|:count devices', $policyDevices->get($item->id)) }} @endif
                            </div>
                        </div>
                        <div class="text-end" style="min-width: 5rem">
                            <div class="fs-5 fw-semibold {{ $score === null ? 'text-body-tertiary' : ($score >= 90 ? 'text-success' : ($score >= 70 ? 'text-warning' : 'text-danger')) }}">{{ $score === null ? '—' : $score.' %' }}</div>
                            <div class="small text-muted">{{ __('passing') }}</div>
                        </div>
                        @if ($isAdmin)
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch m-0">
                                    <input aria-label="{{ __('Enabled') }}" class="form-check-input" type="checkbox" wire:click="togglePolicy({{ $item->id }})" @checked($item->enabled)>
                                </div>
                                <button class="btn btn-sm btn-light" type="button" wire:click="editPolicy({{ $item->id }})">{{ $item->built_in ? __('Show') : __('Edit') }}</button>
                                <button class="btn btn-sm btn-light" type="button" wire:click="duplicatePolicy({{ $item->id }})" title="{{ __('Copy as a new policy') }}"><i class="far fa-copy"></i></button>
                            </div>
                        @endif
                    </div>
                    @if ($segments = $bar($counts))
                        <div class="progress-stacked mt-2" style="height: .5rem">
                            @foreach ($segments as $segment)
                                <div class="progress" role="progressbar" style="width: {{ $segment['width'] }}%" title="{{ __(CompliancePolicies::STATUS_LABELS[$segment['status']]) }}: {{ $segment['count'] }}">
                                    <div class="progress-bar bg-{{ CompliancePolicies::STATUS_COLORS[$segment['status']] }}"></div>
                                </div>
                            @endforeach
                        </div>
                        <div class="d-flex flex-wrap gap-3 small text-muted mt-1">
                            @foreach ($segments as $segment)
                                <span><span class="text-{{ CompliancePolicies::STATUS_COLORS[$segment['status']] }}">●</span> {{ __(CompliancePolicies::STATUS_LABELS[$segment['status']]) }} {{ $segment['count'] }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="list-group-item text-muted py-4 text-center small">
                    {{ __('No compliance policy yet: they come with the rules feed (policies/*.json), or add one.') }}
                </div>
            @endforelse
        </div>
    </div>
@else
    @php($counts = $policyCounts->get($selectedPolicy->id, []))
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <button class="btn btn-sm btn-light" type="button" wire:click="$set('policy', 0)"><i class="fas fa-arrow-left me-1"></i>{{ __('Policies') }}</button>
        <div class="me-auto min-w-0">
            <div class="fw-medium">{{ $selectedPolicy->name }}
                @if ($selectedPolicy->definition['version'] ?? null)<span class="small text-muted fw-normal">{{ $selectedPolicy->definition['version'] }}</span>@endif
            </div>
            @if ($selectedPolicy->definition['description'] ?? null)
                <div class="small text-muted">{{ $selectedPolicy->definition['description'] }}</div>
            @endif
        </div>
        @php($score = CompliancePolicies::score($counts))
        <div class="fw-semibold">{{ $score === null ? '—' : __(':score % passing', ['score' => $score]) }}</div>
    </div>
    @if (! $selectedPolicy->enabled)
        <div class="alert alert-secondary small py-2">{{ __('The policy is switched off: its devices are not evaluated.') }}</div>
    @endif
    <div class="card overflow-hidden">
        <div class="list-group list-group-flush">
            @foreach ($checks as $id => $entry)
                @php($check = $entry['check'])
                <details class="list-group-item" wire:key="compliance-check-{{ md5($id) }}">
                    <summary class="d-flex flex-wrap align-items-center gap-2" style="cursor: pointer">
                        <span class="me-auto min-w-0">
                            <span class="fw-medium">{{ $check['name'] }}</span>
                            <span class="small text-body-tertiary font-monospace ms-1">{{ $id }}</span>
                            @if ($check['reference'] ?? null)<span class="small text-muted ms-1">{{ $check['reference'] }}</span>@endif
                        </span>
                        <x-badge :color="\App\Support\SecurityRules::SEVERITY_COLORS[$check['severity']]" size="sm" variant="subtle">{{ __(ucfirst($check['severity'])) }}</x-badge>
                        @foreach ([CompliancePolicies::FAIL, CompliancePolicies::WARN, CompliancePolicies::MANUAL, CompliancePolicies::PASS, CompliancePolicies::NOT_APPLICABLE] as $status)
                            @if ($entry['counts'][$status] > 0)
                                <x-badge :color="CompliancePolicies::STATUS_COLORS[$status]" size="sm" variant="subtle" title="{{ __(CompliancePolicies::STATUS_LABELS[$status]) }}">{{ __(CompliancePolicies::STATUS_LABELS[$status]) }} {{ $entry['counts'][$status] }}</x-badge>
                            @endif
                        @endforeach
                    </summary>
                    <div class="mt-2 small">
                        @if ($check['description'] ?? null)<div class="text-muted mb-1">{{ $check['description'] }}</div>@endif
                        @if ($check['remediation'] ?? null)<div class="mb-2"><span class="fw-medium">{{ __('Remediation') }}:</span> <span class="text-break">{{ $check['remediation'] }}</span></div>@endif
                        @if ($entry['open']->isEmpty())
                            <div class="text-muted">{{ __('No device fails this check.') }}</div>
                        @else
                            <table class="table table-sm align-middle mb-0">
                                <tbody>
                                    @foreach ($entry['open'] as $result)
                                        <tr>
                                            <td class="text-nowrap"><x-badge :color="$result->statusColor" size="sm" variant="subtle">{{ $result->statusLabel }}</x-badge></td>
                                            <td class="text-nowrap"><a href="{{ route('devices', ['selectedDeviceId' => $result->device_id, 'tab' => 'security']) }}">{{ $result->device?->displayName }}</a></td>
                                            <td class="text-break text-muted">{{ $result->message }}</td>
                                            <td class="text-nowrap text-body-tertiary d-none d-md-table-cell" title="{{ $result->changed_at }}">{{ __('since :time', ['time' => $result->changed_at->diffForHumans()]) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </details>
            @endforeach
        </div>
    </div>
    <div class="small text-muted mt-2">{{ __('SQL Server checks need agent :version or newer on Windows and read access of NT AUTHORITY\SYSTEM to the instance.', ['version' => Device::SQLSERVER_VERSION]) }}</div>
@endif
