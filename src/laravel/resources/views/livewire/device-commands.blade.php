@php
    $hasData = ! empty($selectedDevice->data);
    // All commands are in the menu (the alerts above offer Install all and Restart when needed).
    $menuCommands = [
        'doUpdates' => ['icon' => 'fas fa-sync', 'label' => $pendingUpdates > 0 ? trans_choice('Install :count update|Install :count updates', $pendingUpdates, ['count' => $pendingUpdates]) : __('Install updates'), 'confirm' => null],
        // Collects updates, packages and disk health again now instead of on the next schedule.
        'sync' => ['icon' => 'fas fa-cloud-download-alt', 'label' => __('Sync'), 'confirm' => null, 'title' => __('Collect all data on the device again now and report it')],
        'restart' => ['icon' => 'fas fa-redo', 'label' => __('Restart'), 'confirm' => __('Restart :device now? Unsaved work of its users is lost.', ['device' => $selectedDevice->displayName])],
        'turnOff' => ['icon' => 'fas fa-power-off', 'label' => __('Turn off'), 'confirm' => __('Turn off :device? It can only be started again on site (or with Wake-on-LAN).', ['device' => $selectedDevice->displayName])],
    ];
    // Wake in the menu of every device a magic packet can wake (not virtual machines), disabled
    // with the reason while it cannot (online, no relay in its network, …).
    $canWake = $selectedDevice->isPingOnly || ($hasData && $selectedDevice->virtualization === null);
@endphp
{{-- Reloads when the device reports (live updates over Reverb), e.g. the progress of a command.
     The bell and the menu sit in the top right corner of the device card (position-relative). --}}
<div>
    <div class="position-absolute top-0 end-0 p-3 d-flex align-items-center gap-2">
        @if (! $selectedDevice->offline && ! $selectedDevice->isPingOnly)
            @php
                $power = $selectedDevice->batteryLevel;
                $pluggedIn = $selectedDevice->pluggedIn;
            @endphp
            {{-- The power state, as high as the buttons beside it. --}}
            <span class="align-self-stretch d-none d-sm-inline-flex align-items-center gap-1 px-2 text-body-secondary text-nowrap"
                title="{{ $power === null ? __('Plugged in') : ($pluggedIn ? __('Charging') : ($pluggedIn === false ? __('On battery') : __('Battery'))) }}">
                @if ($power === null)
                    <i class="fas fa-plug"></i>
                @else
                    @if ($pluggedIn)
                        <i class="fas fa-bolt text-warning"></i>
                    @endif
                    <i class="fas {{ $power < 20 ? 'fa-battery-quarter' : ($power < 85 ? 'fa-battery-half' : 'fa-battery-full') }} {{ $power < 20 && ! $pluggedIn ? 'text-danger' : '' }}"></i>
                    <span class="text-body">{{ $power }} %</span>
                @endif
            </span>
        @endif
        <button class="btn btn-light btn-sq" type="button" title="{{ __('Alerts') }}" aria-label="{{ __('Alerts') }}"
            x-on:click="Livewire.dispatch('openModal', {livewireComponents: 'notifications.device-rules', title: @js(__('Alerts for :device', ['device' => $selectedDevice->displayName])), parameters: {deviceId: {{ $selectedDevice->id }}}})">
            <i class="far fa-bell"></i>
        </button>
        {{-- Open state in Alpine (not Bootstrap's JS): a live update of the component keeps it open. --}}
        <div class="dropdown" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
            <button x-bind:aria-expanded="open" aria-label="{{ __('More actions') }}" class="btn btn-light btn-sq" title="{{ __('More actions') }}" type="button" x-on:click="open = ! open" x-bind:class="{ show: open }">
                <i class="fas fa-ellipsis-h"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" data-bs-popper="static" x-bind:class="{ show: open }" x-on:click="if ($event.target.closest('button:not([disabled])')) open = false">
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
                @if ($canWake && ! $selectedDevice->isPingOnly)
                    <li>
                        <button class="dropdown-item" type="button"
                            x-on:click="Livewire.dispatch('openModal', {livewireComponents: 'wake-settings', title: @js(__('Wake-on-LAN of :device', ['device' => $selectedDevice->displayName])), parameters: {deviceId: {{ $selectedDevice->id }}}})">
                            <i class="dropdown-ico fas fa-sliders-h fa-fw"></i>{{ __('Wake-on-LAN settings') }}
                        </button>
                    </li>
                @endif
                @if ($canWake)
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <button class="dropdown-item d-flex align-items-center" type="button" wire:click="wake"
                            title="{{ $wakeRefusal ?? __('Magic packet sent by :relay', ['relay' => $wakeRelay?->displayName]) }}" @disabled($wakeRefusal || $recentWake?->active)>
                            @if ($recentWake?->active)
                                <span aria-hidden="true" class="dropdown-ico spinner-border spinner-border-sm"></span>
                            @else
                                <i class="dropdown-ico fas fa-sun fa-fw"></i>
                            @endif
                            {{ __('Wake') }}
                            @if ($wakeRefusal)
                                <span class="small text-muted ms-2 text-truncate" style="max-width: 12rem">{{ $wakeRefusal }}</span>
                            @endif
                        </button>
                    </li>
                @endif
                @if ($selectedDevice->isPingOnly)
                    {{-- Sync of a ping-only device: the agent that pings it pings it now. --}}
                    <li>
                        <button class="dropdown-item d-flex align-items-center" type="button" wire:click="pingNow"
                            title="{{ $pingNowRefusal ?? __('Ping it now') }}" @disabled($pingNowRefusal || $recentPing?->active)>
                            @if ($recentPing?->active)
                                <span aria-hidden="true" class="dropdown-ico spinner-border spinner-border-sm"></span>
                            @else
                                <i class="dropdown-ico fas fa-cloud-download-alt fa-fw"></i>
                            @endif
                            {{ __('Sync') }}
                            @if ($pingNowRefusal)
                                <span class="small text-muted ms-2 text-truncate" style="max-width: 12rem">{{ $pingNowRefusal }}</span>
                            @endif
                        </button>
                    </li>
                @endif
                @if ($hasData && ! $selectedDevice->offline)
                    <li><hr class="dropdown-divider"></li>
                    @foreach ($menuCommands as $command => $item)
                        @php
                            // A restart and a shutdown exclude each other.
                            $running = \App\Models\Device::findActive($active, $command)
                                ?? (in_array($command, ['restart', 'turnOff'], true) ? (\App\Models\Device::findActive($active, 'restart') ?? \App\Models\Device::findActive($active, 'turnOff')) : null);
                            $refusal = $selectedDevice->commandRefusal($command);
                        @endphp
                        <li>
                            <button class="dropdown-item d-flex align-items-center" type="button" wire:click="sendCommandToDevice('{{ $command }}')"
                                @if ($item['confirm']) wire:confirm="{{ $item['confirm'] }}" @endif
                                @if ($running) title="{{ $running->label }}: {{ $running->statusLabel }}" @elseif ($refusal) title="{{ $refusal }}" @elseif ($item['title'] ?? null) title="{{ $item['title'] }}" @endif
                                @disabled($running || $refusal)>
                                @if ($running && $running->command === $command)
                                    <span aria-hidden="true" class="dropdown-ico spinner-border spinner-border-sm"></span>
                                @else
                                    <i class="dropdown-ico {{ $item['icon'] }} fa-fw"></i>
                                @endif
                                {{ $item['label'] }}
                                @if ($running && $running->command === $command)
                                    <span class="small text-muted ms-2">{{ $running->statusLabel }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
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

    @if ($recentWake?->active)
        {{-- Like the other commands: a progress bar until the device comes online (agents 1.13.2+). --}}
        <div class="mt-3">
            @include('partials.device.command-progress', [
                'command' => $recentWake,
                'note' => in_array($recentWake->status, ['queued', 'sent'], true)
                    ? __('waiting for :relay to send the magic packet', ['relay' => $recentWake->device?->displayName])
                    : ($recentWake->displayMessage ?: __('Magic packet sent by :relay, waiting for the device to come online', ['relay' => $recentWake->device?->displayName])),
            ])
        </div>
    @elseif ($recentWake?->status === 'succeeded')
        {{-- Agents before 1.13.2 are done once the packet is out. --}}
        <div class="small text-muted mt-2">
            <i class="fas fa-sun me-1"></i>{{ __('Magic packet sent by :relay', ['relay' => $recentWake->device?->displayName]) }} · {{ __('waiting for the device to come online…') }}
        </div>
    @elseif (! $recentWake && $selectedDevice->offline && ($hasData || $selectedDevice->isPingOnly) && $wakeRefusal)
        <div class="small text-muted mt-2"><i class="fas fa-sun me-1"></i>{{ __('Wake-on-LAN') }}: {{ $wakeRefusal }}</div>
    @endif

    @if ($recentPing)
        <div class="small mt-2 {{ in_array($recentPing->status, ['failed', 'expired'], true) ? 'text-danger' : 'text-muted' }}">
            <i class="fas fa-network-wired me-1"></i>{{ __('Ping through :relay', ['relay' => $recentPing->device?->displayName]) }}:
            {{ in_array($recentPing->status, ['queued', 'sent'], true) ? __('waiting for :relay', ['relay' => $recentPing->device?->displayName]) : ($recentPing->displayMessage ?: $recentPing->statusLabel) }}
        </div>
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
                    @else
                        {{-- A command the device took can hang (an installer waiting for a window): giving it up frees the way for Restart or a new try. --}}
                        <button class="btn btn-sm btn-link text-body-secondary p-0" title="{{ __('Give up waiting') }}" type="button" wire:click="cancel({{ $command->id }})"
                            wire:confirm="{{ __('Stop waiting for :command? The device may still be working on it, but it no longer blocks other commands (e.g. Restart).', ['command' => $command->label]) }}">
                            <i class="fas fa-times"></i>
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
