<div wire:poll>
    @if ($selectedDevice->offline)
        <div class="alert alert-secondary mb-0" role="alert">
            {{ __('Device is offline!') }}
        </div>
    @else
        @if ($selectedDevice->restartPending)
            <div class="alert alert-warning mb-0" role="alert">
                {{ __('Device is in restart pending state!') }}
            </div>
        @endif
    @endif
</div>
