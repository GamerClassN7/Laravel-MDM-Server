{{-- Installs one update ($section, $index of the row, $target from Device::updateTarget), or shows its progress. --}}
@php
    $running = $target ? \App\Models\Device::findActive($activeCommands, 'installUpdate', $target) : null;
    $call = "installUpdate('{$section}', {$index}, " . \Illuminate\Support\Js::from($target['id'] ?? '') . ")";
@endphp
@if ($running)
    <div style="min-width: 8rem;">@include('partials.device.command-progress', ['command' => $running, 'compact' => true])</div>
@elseif ($target && ! $updatesRunning)
    <button class="btn btn-sm btn-outline-primary text-nowrap" type="button" title="{{ __('Install only this update') }}"
        wire:click="{{ $call }}" wire:loading.attr="disabled" wire:target="{{ $call }}" @disabled($selectedDevice->offline)>
        <i class="fas fa-download me-1" wire:loading.remove wire:target="{{ $call }}"></i>
        <span aria-hidden="true" class="spinner-border spinner-border-sm me-1" wire:loading wire:target="{{ $call }}"></span>
        {{ __('Update') }}
    </button>
@endif
