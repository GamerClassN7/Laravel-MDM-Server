<div class="mt-4" wire:poll.30s>
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0">{{ __('Performance') }}</h5>
        {{-- Boilerplate segmented switch. --}}
        <ul class="nav nav-switch" role="tablist">
            @foreach (array_keys(\App\Livewire\DeviceMetrics::RANGES) as $option)
                <li class="nav-item">
                    <button class="nav-link {{ $range === $option ? 'active' : '' }}" type="button" wire:click="setRange('{{ $option }}')">{{ $option }}</button>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="row g-3">
        <div class="col-12 col-md-6">
            <x-metric-chart
                :current="$latest ? round($latest->cpu).' %' : null"
                :from="$from"
                :labels="$labels"
                :to="$to"
                :title="__('CPU')"
                :values="$cpu"
                color="var(--bs-primary)"
            />
        </div>
        <div class="col-12 col-md-6">
            <x-metric-chart
                :current="$latest ? round($latest->memory_percent).' %' : null"
                :from="$from"
                :labels="$labels"
                :to="$to"
                :subtitle="$latest ? __(':used of :total GB', ['used' => round($latest->memory_used / 1024 ** 3, 1), 'total' => round($latest->memory_total / 1024 ** 3, 1)]) : null"
                :title="__('Memory')"
                :values="$memory"
                color="var(--bs-success)"
            />
        </div>
    </div>
</div>
