<div class="d-flex flex-wrap gap-2 mt-3" @if (!empty($selectedDevice->data)) wire:poll @endif>
    @if (!empty($selectedDevice->data))
        @foreach (['turnOff' => ['fas fa-power-off', __('Turn off')], 'restart' => ['fas fa-redo', __('Restart')], 'doUpdates' => ['fas fa-sync', __('Install updates')]] as $command => [$icon, $label])
            <button class="btn btn-light" type="button" wire:click.prevent="sendCommandToDevice('{{ $command }}')" @disabled($selectedDevice->offline)>
                @if (in_array($command, $selectedDevice->commands) && !$selectedDevice->offline)
                    <span aria-hidden="true" class="spinner-border spinner-border-sm me-2" role="status"></span>
                @else
                    <i class="{{ $icon }} me-2"></i>
                @endif
                {{ $label }}
            </button>
        @endforeach
    @endif
    <button class="btn btn-danger ms-auto" type="button" wire:click.prevent="deleteDevice()" wire:confirm="{{ __('Do you really want to delete this device?') }}">
        <i class="fas fa-trash me-2"></i>{{ __('Delete') }}
    </button>
</div>
