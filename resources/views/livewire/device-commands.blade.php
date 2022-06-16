<div wire:poll>
    <button wire:click.prevent="sendCommandToDevice('turnOff')" class="btn btn-primary mt-2 btn-block" type="button">
        @if (in_array('turnOff', $selectedDevice->commands))
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
        @else
            <i class="bi bi-power"></i>
        @endif
        {{ __('turnoff') }}
    </button>
    <button wire:click.prevent="sendCommandToDevice('restart')" class="btn btn-primary mt-2 btn-block" type="button">
        @if (in_array('restart', $selectedDevice->commands))
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
        @else
            <i class="bi bi-arrow-clockwise"></i>
        @endif
        {{ __('restart') }}
    </button>
        <button wire:click.prevent="sendCommandToDevice('doUpdates')" class="btn btn-primary mt-2 btn-block" type="button">
        @if (in_array('doUpdates', $selectedDevice->commands))
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
        @else
            <i class="bi bi-arrow-repeat"></i>
        @endif
        {{ __('updates') }}
    </button>
</div>
