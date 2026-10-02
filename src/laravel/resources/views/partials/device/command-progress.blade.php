{{-- One command on its way: label, state ($note instead of it), progress bar (indeterminate until the agent reports percent). --}}
@php
    /** @var \App\Models\DeviceCommand $command */
    $percent = $command->progress;
@endphp
<div class="small" wire:key="command-progress-{{ $command->id }}">
    <div class="d-flex justify-content-between gap-2">
        <span class="text-truncate">
            @unless ($compact ?? false)
                <i class="{{ $command->icon }} me-1 text-body-secondary"></i>{{ $command->label }} ·
            @endunless
            <span class="text-body-secondary">{{ ($note ?? null) ?: $command->displayMessage ?: $command->statusLabel }}</span>
        </span>
        @if ($percent !== null && $command->status === 'running')
            <span class="text-nowrap fw-semibold">{{ $percent }} %</span>
        @endif
    </div>
    <div class="progress mt-1" role="progressbar" style="height: 4px;" aria-label="{{ $command->label }}" @if ($percent !== null) aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100" @endif>
        <div class="progress-bar {{ $percent === null || $command->status !== 'running' ? 'progress-bar-striped progress-bar-animated' : '' }} {{ $command->status === 'queued' ? 'bg-secondary opacity-25' : '' }}" style="width: {{ $percent !== null && $command->status === 'running' ? max(3, $percent) : 100 }}%"></div>
    </div>
</div>
