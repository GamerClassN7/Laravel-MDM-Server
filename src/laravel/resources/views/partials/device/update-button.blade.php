{{-- Installs one update ($section, $index of the row, $target from Device::updateTarget), or shows its progress. --}}
@php
    $running = $target ? \App\Models\Device::findActive($activeCommands, 'installUpdate', $target) : null;
    $call = "installUpdate('{$section}', {$index}, " . \Illuminate\Support\Js::from($target['id'] ?? '') . ")";
@endphp
@if ($running)
    <div style="min-width: 8rem;">@include('partials.device.command-progress', ['command' => $running, 'compact' => true])</div>
@elseif ($target && ! $updatesRunning)
    <button class="btn btn-sm btn-light text-nowrap" type="button" title="{{ __('Install only this update') }}" aria-label="{{ __('Update') }}"
        wire:click="{{ $call }}" wire:loading.attr="disabled" wire:target="{{ $call }}" @disabled($selectedDevice->offline)>
        <i class="fas fa-download me-sm-1 text-primary" wire:loading.remove wire:target="{{ $call }}"></i>
        <span aria-hidden="true" class="spinner-border spinner-border-sm me-sm-1" wire:loading wire:target="{{ $call }}"></span>
        {{-- An icon only on phones. --}}
        <span class="d-none d-sm-inline">{{ __('Update') }}</span>
    </button>
@endif
