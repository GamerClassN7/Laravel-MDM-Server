<div wire:poll>
    @if ($selectedDevice->offline)
        <div class="alert alert-secondary mt-3 mb-0" role="alert">
            <i class="fas fa-plug me-2"></i>{{ __('Device is offline!') }}
        </div>
    @elseif ($selectedDevice->restartPending)
        <div class="alert alert-warning mt-3 mb-0" role="alert">
            <i class="fas fa-redo me-2"></i>{{ __('Device is in restart pending state!') }}
        </div>
    @endif
</div>
