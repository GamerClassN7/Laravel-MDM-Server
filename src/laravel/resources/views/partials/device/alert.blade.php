{{--
    One smart alert (App\Support\SmartAlerts) with its action. $device is the alert's device;
    $showDevice lists the device name (dashboard widget); the action calls runAlert($key) or,
    with $deviceArgument, runAlert($deviceId, $key).
--}}
@php
    $action = $alert['action'];
    $active = $alert['active'];
    $buttonColor = match ($alert['severity']) { 'danger' => 'danger', 'warning' => 'warning', default => 'primary' };
    $call = ($deviceArgument ?? false) ? "runAlert({$device->id}, '{$alert['key']}')" : "runAlert('{$alert['key']}')";
@endphp
<div class="list-group-item d-flex gap-3 align-items-start" wire:key="alert-{{ $device->id }}-{{ $alert['key'] }}">
    <i class="{{ $alert['icon'] }} fa-fw text-{{ $alert['severity'] === 'secondary' ? 'body-secondary' : $alert['severity'] }} mt-1"></i>
    {{-- Text and actions wrap: on narrow screens the buttons go below the text. --}}
    <div class="flex-grow-1 min-w-0 d-flex flex-wrap align-items-start column-gap-3 row-gap-2">
    <div class="flex-grow-1 min-w-0" style="flex-basis: 14rem;">
        @if ($showDevice ?? false)
            <a class="small fw-semibold text-decoration-none d-block text-truncate" href="{{ route('devices', array_filter(['selectedDeviceId' => $device->id, 'tab' => $alert['tab']])) }}">
                <i class="{{ $device->typeIcon }} me-1 text-body-secondary"></i>{{ $device->displayName }}
                @if ($device->offline && $alert['key'] !== 'offline')
                    <x-badge class="ms-1" color="secondary" size="sm" variant="subtle">{{ __('Offline') }}</x-badge>
                @endif
            </a>
        @endif
        <div class="fw-semibold">{{ $alert['title'] }}</div>
        @if ($alert['message'])
            <div class="small text-body-secondary text-break">{{ $alert['message'] }}</div>
        @endif
        @if ($alert['failure'])
            <div class="small text-danger"><i class="fas fa-times-circle me-1"></i>{{ __('Last attempt failed: :reason', ['reason' => $alert['failure']]) }}</div>
        @endif
        @error('alert.'.($deviceArgument ?? false ? $device->id.'.' : '').$alert['key'])
            <div class="small text-danger">{{ $message }}</div>
        @enderror
        @if ($active)
            <div class="mt-2">@include('partials.device.command-progress', ['command' => $active, 'compact' => true])</div>
        @endif
        @if ($alert['copy'] && ! ($showDevice ?? false))
            <details class="mt-2">
                <summary class="small text-body-secondary">{{ __('Or run on the device (:how)', ['how' => $device->platform === 'linux' ? 'sudo pwsh' : __('PowerShell as Administrator')]) }}</summary>
                <div class="mt-2"><x-copy-command :command="$alert['copy']" /></div>
            </details>
        @endif
    </div>
    <div class="d-flex flex-wrap justify-content-end gap-2 flex-shrink-0 ms-auto">
        @if ($alert['tab'] && ! ($showDevice ?? false))
            <button class="btn btn-sm btn-link text-decoration-none" type="button" x-on:click="$dispatch('show-device-tab', { tab: @js($alert['tab']) })">{{ __('Details') }}</button>
        @endif
        @if ($action)
            <button class="btn btn-sm btn-{{ $buttonColor }} text-nowrap" type="button"
                wire:click="{{ $call }}"
                wire:loading.attr="disabled" wire:target="{{ $call }}"
                @if ($action['confirm']) wire:confirm="{{ $action['confirm'] }}" @endif
                @if ($alert['refusal']) title="{{ $alert['refusal'] }}" @endif
                @disabled($active || $alert['refusal'])>
                @if ($active)
                    <span aria-hidden="true" class="spinner-border spinner-border-sm me-1"></span>
                @else
                    <i class="{{ $action['icon'] }} me-1" wire:loading.remove wire:target="{{ $call }}"></i>
                    <span aria-hidden="true" class="spinner-border spinner-border-sm me-1" wire:loading wire:target="{{ $call }}"></span>
                @endif
                {{ $action['label'] }}
            </button>
        @endif
    </div>
</div>
</div>
