<div class="mt-4">
    @include('partials.chart-section-header', [
        'icon' => 'fas fa-chart-line',
        'title' => e(__('Performance')),
        'subtitle' => e($latest ? __('Every 30 s by the agent, last :time', ['time' => $latest->created_at->diffForHumans()]) : __('No data yet: the agent sends it every 30 s')),
        'ranges' => array_keys(\App\Livewire\DeviceMetrics::RANGES),
        'range' => $range,
    ])

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
