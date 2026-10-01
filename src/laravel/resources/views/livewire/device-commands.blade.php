@php
    $hasData = ! empty($selectedDevice->data);
    // The safe actions are buttons (the most useful one primary), Turn off is in the menu.
    $buttons = [
        'doUpdates' => ['icon' => 'fas fa-sync', 'label' => $pendingUpdates > 0 ? trans_choice('Install :count update|Install :count updates', $pendingUpdates, ['count' => $pendingUpdates]) : __('Install updates'), 'confirm' => null, 'primary' => $pendingUpdates > 0],
        'restart' => ['icon' => 'fas fa-redo', 'label' => __('Restart'), 'confirm' => __('Restart :device now? Unsaved work of its users is lost.', ['device' => $selectedDevice->displayName]), 'primary' => false],
        // Collects updates, packages and disk health again now instead of on the next schedule.
        'sync' => ['icon' => 'fas fa-cloud-download-alt', 'label' => __('Sync'), 'confirm' => null, 'primary' => false, 'title' => __('Collect all data on the device again now and report it')],
    ];
    $turnOffRunning = \App\Models\Device::findActive($active, 'turnOff') ?? \App\Models\Device::findActive($active, 'restart');
    $turnOffRefusal = $selectedDevice->commandRefusal('turnOff');
@endphp
{{-- Polls faster while a command is on its way, so its progress stays current. --}}
<div class="mt-3" @if ($active->isNotEmpty() || $recentWake?->active) wire:poll.2s @elseif ($hasData) wire:poll.15s @endif>
    <div class="d-flex flex-wrap align-items-center gap-2">
        @if ($selectedDevice->offline && ($hasData || $selectedDevice->isPingOnly))
            <button class="btn {{ $wakeRefusal ? 'btn-light' : 'btn-primary' }}" type="button" wire:click="wake" wire:loading.attr="disabled" wire:target="wake"
                title="{{ $wakeRefusal ?? __('Magic packet sent by :relay', ['relay' => $wakeRelay?->displayName]) }}" @disabled($wakeRefusal || $recentWake?->active)>
                @if ($recentWake?->active)
                    <span aria-hidden="true" class="spinner-border spinner-border-sm me-2" role="status"></span>
                @else
                    <i class="fas fa-sun me-2"></i>
                @endif
                {{ __('Wake') }}
            </button>
        @elseif ($hasData)
            @foreach ($buttons as $command => $button)
                @php
                    $running = \App\Models\Device::findActive($active, $command) ?? ($command === 'restart' ? \App\Models\Device::findActive($active, 'turnOff') : null);
                    $refusal = $selectedDevice->commandRefusal($command);
                @endphp
                <button class="btn {{ $button['primary'] && ! $running ? 'btn-primary' : 'btn-light' }}" type="button"
                    wire:click="sendCommandToDevice('{{ $command }}')"
                    wire:loading.attr="disabled" wire:target="sendCommandToDevice('{{ $command }}')"
                    @if ($button['confirm']) wire:confirm="{{ $button['confirm'] }}" @endif
                    @if ($running) title="{{ $running->label }}: {{ $running->statusLabel }}" @elseif ($refusal) title="{{ $refusal }}" @elseif ($button['title'] ?? null) title="{{ $button['title'] }}" @endif
                    @disabled($running || $refusal)>
                    @if ($running && $running->command === $command)
                        <span aria-hidden="true" class="spinner-border spinner-border-sm me-2" role="status"></span>
                    @else
                        <i class="{{ $button['icon'] }} me-2"></i>
                    @endif
                    {{ $button['label'] }}
                </button>
            @endforeach
        @endif

        <button class="btn btn-light btn-sq ms-auto" type="button" title="{{ __('Alerts') }}" aria-label="{{ __('Alerts') }}"
            x-on:click="Livewire.dispatch('openModal', {livewireComponents: 'notifications.device-rules', title: @js(__('Alerts for :device', ['device' => $selectedDevice->displayName])), parameters: {deviceId: {{ $selectedDevice->id }}}})">
            <i class="far fa-bell"></i>
        </button>
        <div class="dropdown">
            <button aria-expanded="false" aria-label="{{ __('More actions') }}" class="btn btn-light btn-sq" data-bs-toggle="dropdown" title="{{ __('More actions') }}" type="button">
                <i class="fas fa-ellipsis-h"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                {{-- The agent update is in the "newer agent" alert, not repeated here. --}}
                <li>
                    <button class="dropdown-item" type="button" wire:click="$dispatch('rename-device')">
                        <i class="dropdown-ico fas fa-pen fa-fw"></i>{{ __('Rename') }}
                    </button>
                </li>
                <li>
                    <button class="dropdown-item" type="button" wire:click="$dispatch('edit-device-tags')">
                        <i class="dropdown-ico fas fa-tags fa-fw"></i>{{ __('Edit tags') }}
                    </button>
                </li>
                @if ($selectedDevice->isPingOnly)
                    <li>
                        <button class="dropdown-item" type="button"
                            x-on:click="Livewire.dispatch('openModal', {livewireComponents: 'ping-settings', title: @js(__('Ping settings of :device', ['device' => $selectedDevice->displayName])), parameters: {deviceId: {{ $selectedDevice->id }}}})">
                            <i class="dropdown-ico fas fa-network-wired fa-fw"></i>{{ __('Ping settings') }}
                        </button>
                    </li>
                @endif
                @if ($hasData)
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <button class="dropdown-item" type="button" wire:click="sendCommandToDevice('turnOff')"
                            wire:confirm="{{ __('Turn off :device? It can only be started again on site (or with Wake-on-LAN).', ['device' => $selectedDevice->displayName]) }}"
                            @disabled($turnOffRunning || $turnOffRefusal) @if ($turnOffRefusal) title="{{ $turnOffRefusal }}" @endif>
                            <i class="dropdown-ico fas fa-power-off fa-fw"></i>{{ __('Turn off') }}
                        </button>
                    </li>
                @endif
                <li><hr class="dropdown-divider"></li>
                <li>
                    <button class="dropdown-item text-danger" type="button" wire:click.prevent="deleteDevice()" wire:confirm="{{ __('Do you really want to delete this device?') }}">
                        <i class="dropdown-ico fas fa-trash fa-fw text-danger"></i>{{ __('Delete device') }}
                    </button>
                </li>
            </ul>
        </div>
    </div>

    @if ($recentWake)
        <div class="small mt-2 {{ $recentWake->status === 'failed' || $recentWake->status === 'expired' ? 'text-danger' : 'text-muted' }}">
            <i class="fas fa-sun me-1"></i>{{ __('Wake-on-LAN through :relay', ['relay' => $recentWake->device?->displayName]) }}:
            {{ in_array($recentWake->status, ['queued', 'sent'], true) ? __('waiting for :relay to send the magic packet', ['relay' => $recentWake->device?->displayName]) : $recentWake->statusLabel }}@if ($recentWake->resultNote) · {{ $recentWake->resultNote }}@endif
            @if ($recentWake->status === 'succeeded')
                · {{ __('waiting for the device to come online…') }}
            @endif
        </div>
    @elseif ($selectedDevice->offline && ($hasData || $selectedDevice->isPingOnly) && $wakeRefusal)
        <div class="small text-muted mt-2"><i class="fas fa-sun me-1"></i>{{ __('Wake-on-LAN') }}: {{ $wakeRefusal }}</div>
    @endif

    @error('command')
        <div class="small text-danger mt-2">{{ $message }}</div>
    @enderror

    @if ($active->isNotEmpty())
        <div class="vstack gap-2 mt-3">
            @foreach ($active as $command)
                <div class="d-flex align-items-center gap-2" wire:key="active-command-{{ $command->id }}">
                    <div class="flex-grow-1 min-w-0">@include('partials.device.command-progress', ['command' => $command])</div>
                    @if ($command->status === 'queued')
                        <button class="btn btn-sm btn-link text-body-secondary p-0" title="{{ __('Cancel') }}" type="button" wire:click="cancel({{ $command->id }})">
                            <i class="fas fa-times"></i>
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
