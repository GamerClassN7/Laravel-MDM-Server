<div wire:poll>
    <button wire:click.prevent="sendCommandToDevice('turnOff')" class="btn btn-primary mt-2 btn-block {{ $selectedDevice->offline ? 'disabled' : '' }}" type="button">
        @if (in_array('turnOff', $selectedDevice->commands) && !$selectedDevice->offline)
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
        @else
            <i class="bi bi-power"></i>
        @endif
        {{ __('turnoff') }}
    </button>
    <button wire:click.prevent="sendCommandToDevice('restart')" class="btn btn-primary mt-2 btn-block {{ $selectedDevice->offline ? 'disabled' : '' }}" type="button">
        @if (in_array('restart', $selectedDevice->commands) && !$selectedDevice->offline)
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
        @else
            <i class="bi bi-arrow-clockwise"></i>
        @endif
        {{ __('restart') }}
    </button>
    <button wire:click.prevent="sendCommandToDevice('doUpdates')" class="btn btn-primary mt-2 btn-block {{ $selectedDevice->offline ? 'disabled' : '' }}" type="button">
        @if (in_array('doUpdates', $selectedDevice->commands) && !$selectedDevice->offline)
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
        @else
            <i class="bi bi-arrow-repeat"></i>
        @endif
        {{ __('updates') }}
    </button>
    <button wire:click.prevent="deleteDevice()" class="btn btn-danger mt-2 btn-block" type="button">
        <i class="bi bi-trash"></i>
        {{ __('delete') }}
    </button>
</div>
