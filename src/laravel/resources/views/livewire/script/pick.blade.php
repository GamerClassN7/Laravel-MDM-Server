<div>
    <p class="small text-muted">
        {{ trans_choice('On :count selected device: :names.|On :count selected devices: :names.', $devices->count(), ['count' => $devices->count(), 'names' => $devices->map->displayName->implode(', ')]) }}
        {{ __('Devices on another platform, with an unsigned agent or with scripts disabled are skipped.') }}
    </p>
    <div class="list-group">
        @forelse ($scripts as $item)
            @php($script = $item['script'])
            <div class="list-group-item d-flex align-items-center gap-3" wire:key="pick-script-{{ $script->id }}">
                <i class="{{ match ($script->platform) { 'windows' => 'fab fa-windows', 'linux' => 'fab fa-linux', default => 'fas fa-desktop' } }} fa-fw text-body-secondary"></i>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold text-truncate">{{ $script->name }}</div>
                    <div class="small text-muted">{{ trans_choice(':count of them can run it|:count of them can run it', $item['runnable'], ['count' => $item['runnable']]) }}</div>
                </div>
                <button class="btn btn-sm btn-primary" type="button" wire:click="run({{ $script->id }})" @disabled($item['runnable'] === 0)>
                    <i class="fas fa-play me-1"></i>{{ __('Run') }}
                </button>
            </div>
        @empty
            <div class="list-group-item text-muted">{{ __('No scripts yet.') }} <a href="{{ route('script.index') }}">{{ __('Add one') }}</a></div>
        @endforelse
    </div>
</div>
