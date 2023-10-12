<div @if (!empty($selectedDevice->data)) wire:poll @endif>
    @if (!empty($selectedDevice->data))
        <button class="btn btn-primary mt-2 btn-block {{ $selectedDevice->offline ? 'disabled' : '' }}" type="button" wire:click.prevent="sendCommandToDevice('turnOff')">
            @if (in_array('turnOff', $selectedDevice->commands) && !$selectedDevice->offline)
                <span aria-hidden="true" class="spinner-border spinner-border-sm" role="status"></span>
            @else
                <i class="bi bi-power"></i>
            @endif
            {{ __('turnoff') }}
        </button>
        <button class="btn btn-primary mt-2 btn-block {{ $selectedDevice->offline ? 'disabled' : '' }}" type="button" wire:click.prevent="sendCommandToDevice('restart')">
            @if (in_array('restart', $selectedDevice->commands) && !$selectedDevice->offline)
                <span aria-hidden="true" class="spinner-border spinner-border-sm" role="status"></span>
            @else
                <i class="bi bi-arrow-clockwise"></i>
            @endif
            {{ __('restart') }}
        </button>
        <button class="btn btn-primary mt-2 btn-block {{ $selectedDevice->offline ? 'disabled' : '' }}" type="button" wire:click.prevent="sendCommandToDevice('doUpdates')">
            @if (in_array('doUpdates', $selectedDevice->commands) && !$selectedDevice->offline)
                <span aria-hidden="true" class="spinner-border spinner-border-sm" role="status"></span>
            @else
                <i class="bi bi-arrow-repeat"></i>
            @endif
            {{ __('updates') }}
        </button>
    @endif
    <button class="btn btn-danger mt-2 btn-block" type="button" wire:click.prevent="deleteDevice()">
        <i class="bi bi-trash"></i>
        {{ __('delete') }}
    </button>
</div>
