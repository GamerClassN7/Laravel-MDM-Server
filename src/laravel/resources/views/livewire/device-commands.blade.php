@php
    $hasData = ! empty($selectedDevice->data);
    $buttons = [
        'restart' => ['icon' => 'fas fa-redo', 'label' => __('Restart'), 'confirm' => __('Restart :device now? Unsaved work of its users is lost.', ['device' => $selectedDevice->displayName])],
        'turnOff' => ['icon' => 'fas fa-power-off', 'label' => __('Turn off'), 'confirm' => __('Turn off :device? It can only be started again on site (or with Wake-on-LAN).', ['device' => $selectedDevice->displayName])],
        'doUpdates' => ['icon' => 'fas fa-sync', 'label' => __('Install updates'), 'confirm' => null],
        // Collects updates, packages and disk health again now instead of on the next schedule.
        'sync' => ['icon' => 'fas fa-cloud-download-alt', 'label' => __('Sync'), 'confirm' => null, 'title' => __('Collect all data on the device again now and report it')],
    ];
@endphp
{{-- Polls faster while a command is on its way, so its progress stays current. --}}
<div class="mt-3" @if ($active->isNotEmpty()) wire:poll.2s @elseif ($hasData) wire:poll.15s @endif>
    <div class="d-flex flex-wrap align-items-center gap-2">
        @if ($hasData)
            @foreach ($buttons as $command => $button)
                @php
                    $running = \App\Models\Device::findActive($active, $command) ?? ($command === 'turnOff' ? \App\Models\Device::findActive($active, 'restart') : ($command === 'restart' ? \App\Models\Device::findActive($active, 'turnOff') : null));
                    $refusal = $selectedDevice->commandRefusal($command);
                @endphp
                <button class="btn btn-light" type="button"
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
                    @if ($command === 'doUpdates' && $pendingUpdates > 0)
                        <x-badge class="ms-1" color="warning" size="sm" variant="subtle">{{ $pendingUpdates }}</x-badge>
                    @endif
                </button>
            @endforeach
        @endif

        <div class="dropdown ms-auto">
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
                <li><hr class="dropdown-divider"></li>
                <li>
                    <button class="dropdown-item text-danger" type="button" wire:click.prevent="deleteDevice()" wire:confirm="{{ __('Do you really want to delete this device?') }}">
                        <i class="dropdown-ico fas fa-trash fa-fw text-danger"></i>{{ __('Delete device') }}
                    </button>
                </li>
            </ul>
        </div>
    </div>

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
