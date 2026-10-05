{{-- One finding of the scanner: severity, rule, message, since when; acknowledge / open again. --}}
<div class="list-group-item d-flex align-items-start gap-3 py-3" wire:key="security-finding-{{ $finding->id }}">
    <span class="icon-tile bg-{{ $finding->severityColor }}-subtle text-{{ $finding->severityColor }}-emphasis" title="{{ __(ucfirst($finding->severity)) }}"><i class="fas fa-shield-alt"></i></span>
    <div class="flex-grow-1 min-w-0">
        <div class="d-flex flex-wrap align-items-center column-gap-2">
            <x-badge :color="$finding->severityColor" size="sm" variant="subtle">{{ __(ucfirst($finding->severity)) }}</x-badge>
            <span class="fw-semibold">{{ $finding->rule?->name }}</span>
            @if ($showDevice ?? false)
                <span class="text-body-secondary">·</span>
                <a class="text-body" href="{{ route('devices', ['selectedDeviceId' => $finding->device_id, 'tab' => 'security']) }}">{{ $finding->device?->displayName }}</a>
            @endif
        </div>
        <div class="small text-break mt-1">{{ $finding->message }}</div>
        @if ($finding->rule?->definition['remediation'] ?? null)
            <div class="small text-muted mt-1"><i class="fas fa-wrench me-1"></i>{{ $finding->rule->definition['remediation'] }}</div>
        @endif
        <div class="small text-body-tertiary mt-1">
            <span title="{{ $finding->first_seen_at }}">{{ __('Found :time', ['time' => $finding->first_seen_at->diffForHumans()]) }}</span>
            @if ($finding->occurrences > 1)
                · {{ trans_choice(':count time|:count times', $finding->occurrences) }}, {{ __('last :time', ['time' => $finding->last_seen_at->diffForHumans()]) }}
            @endif
            @if ($finding->resolved_at && ! $finding->acknowledged_at)
                · <span title="{{ $finding->resolved_at }}">{{ __('resolved :time', ['time' => $finding->resolved_at->diffForHumans()]) }}</span>
            @endif
            @if ($finding->acknowledged_at)
                · <span title="{{ $finding->acknowledged_at }}">{{ __('acknowledged by :user :time', ['user' => $finding->acknowledger?->name ?? '?', 'time' => $finding->acknowledged_at->diffForHumans()]) }}</span>
            @endif
        </div>
    </div>
    @if ($finding->acknowledged_at)
        @if (! $finding->resolved_at || $finding->rule?->isEvent)
            <button class="btn btn-sm btn-light text-nowrap" type="button" wire:click="reopen({{ $finding->id }})">{{ __('Open again') }}</button>
        @endif
    @elseif (! $finding->resolved_at)
        <button class="btn btn-sm btn-light text-nowrap" type="button" wire:click="acknowledge({{ $finding->id }})" title="{{ $finding->rule?->isEvent ? __('Done with it') : __('Known, stays quiet until it is gone') }}">
            <i class="fas fa-check me-1"></i>{{ __('Acknowledge') }}
        </button>
    @endif
</div>
