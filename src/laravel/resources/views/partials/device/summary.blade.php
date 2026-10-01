{{-- Four tiles under the device card: CPU, memory, the fullest drive and the updates. --}}
@php
    $latest = $selectedDevice->offline ? null : $selectedDevice->metrics()->latest('created_at')->first();
    $drive = collect($selectedDevice->drives)->filter(fn ($drive) => isset($drive['PercentUsed']))->sortByDesc('PercentUsed')->first();
    $memoryFree = $latest ? max(0, $latest->memory_total - $latest->memory_used) : null;
    $tiles = [
        [
            'label' => __('CPU'), 'aside' => isset($selectedDevice->data->machine->Cores) ? __(':count cores', ['count' => $selectedDevice->data->machine->Cores]) : null,
            'value' => $latest ? round($latest->cpu).' %' : '–', 'percent' => $latest ? $latest->cpu : 0, 'bar' => 'bg-primary',
            'note' => $latest ? __('Now, every 30 s') : __('No data while offline'),
        ],
        [
            'label' => __('Memory'), 'aside' => $latest ? \App\Support\Bytes::format($latest->memory_total) : null,
            'value' => $latest ? round($latest->memory_percent).' %' : '–', 'percent' => $latest ? $latest->memory_percent : 0, 'bar' => 'bg-success',
            'note' => $latest ? __(':free free', ['free' => \App\Support\Bytes::format($memoryFree)]) : __('No data while offline'),
        ],
        [
            'label' => __('Fullest drive'), 'aside' => $drive ? trim(($drive['FriendlyName'] ?? '').' ('.($drive['DriveLetter'] ?? '?').')') : null,
            'value' => $drive ? $drive['PercentUsed'].' %' : '–', 'percent' => $drive['PercentUsed'] ?? 0,
            'bar' => ($drive['PercentUsed'] ?? 0) >= 95 ? 'bg-danger' : (($drive['PercentUsed'] ?? 0) >= 90 ? 'bg-warning' : 'bg-primary'),
            'note' => $drive ? __(':free free of :size', ['free' => \App\Support\Bytes::format($drive['SizeRemaining'] ?? 0), 'size' => \App\Support\Bytes::format($drive['Size'] ?? 0)]) : __('No drives reported'),
            'tab' => 'drives',
        ],
        [
            'label' => __('Updates'), 'aside' => null,
            'value' => $pendingUpdates, 'percent' => $pendingUpdates > 0 ? 100 : 0, 'bar' => 'bg-warning',
            'note' => $pendingUpdates > 0 ? ($selectedDevice->restartPending ? __('Restart required') : __('Ready to install')) : ($selectedDevice->restartPending ? __('Restart required') : __('Up to date')),
            'tab' => 'updates',
        ],
    ];
@endphp
<div class="row g-3 mt-0">
    @foreach ($tiles as $tile)
        <div class="col-6 col-xl-3">
            <div class="card card-body h-100">
                <div class="d-flex justify-content-between small text-muted gap-2">
                    <span>{{ $tile['label'] }}</span>
                    <span class="text-truncate">{{ $tile['aside'] }}</span>
                </div>
                <div class="fs-4 fw-semibold mt-1">
                    @isset($tile['tab'])
                        <a class="text-body text-decoration-none" href="#" x-on:click.prevent="$wire.tab = '{{ $tile['tab'] }}'; document.getElementById('{{ $tile['tab'] }}-tab')?.click()">{{ $tile['value'] }}</a>
                    @else
                        {{ $tile['value'] }}
                    @endisset
                </div>
                <div class="progress mt-2" style="height: 6px" role="progressbar" aria-label="{{ $tile['label'] }}" aria-valuenow="{{ round($tile['percent']) }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar {{ $tile['bar'] }}" style="width: {{ min(100, $tile['percent']) }}%"></div>
                </div>
                <div class="small text-muted mt-2">{{ $tile['note'] }}</div>
            </div>
        </div>
    @endforeach
</div>
