<div>
    <div class="d-flex flex-wrap align-items-center gap-2 small text-muted mb-2">
        <x-badge :color="$run->statusColor" size="sm" variant="subtle">{{ __(ucfirst($run->status)) }}</x-badge>
        <span>{{ $run->script?->name }} v{{ $run->version }}</span>
        <span>· {{ $run->device?->displayName }}</span>
        @if ($run->finished_at)
            <span title="{{ $run->finished_at }}">· {{ $run->finished_at->diffForHumans() }}</span>
        @endif
    </div>
    @if ($run->error)
        <div class="alert alert-danger small py-2">{{ $run->error }}</div>
    @endif
    @if ($run->output)
        <pre class="small bg-body-tertiary p-3 rounded mb-0" style="max-height: 60vh; white-space: pre-wrap; word-break: break-word;"><code>{{ $run->output }}</code></pre>
    @else
        <p class="text-muted mb-0">{{ __('No output.') }}</p>
    @endif
</div>
