<div wire:poll>
    @if ($selectedDevice->offline)
        <div class="alert alert-secondary" role="alert">
            {{ __('Device is offline!') }}
        </div>
    @endif
    @if ($selectedDevice->restartPending)
        <div class="alert alert-warning" role="alert">
            {{ __('Device is in restart pending state!') }}
        </div>
    @endif
</div>
