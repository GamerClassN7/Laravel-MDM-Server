<?php

namespace App\Livewire;

use App\Models\DeviceMetric;
use Carbon\CarbonImmutable;
use Livewire\Component;

class DeviceMetrics extends Component
{
    /** Range => [length in seconds, number of chart points]. */
    public const RANGES = [
        '1h' => [3600, 60],
        '24h' => [86400, 96],
        '7d' => [604800, 84],
    ];

    public $selectedDeviceId;

    public string $range = '1h';

    public function setRange(string $range): void
    {
        if (array_key_exists($range, self::RANGES)) {
            $this->range = $range;
        }
    }

    public function render()
    {
        [$length, $points] = self::RANGES[$this->range];
        $bucket = intdiv($length, $points);
        $end = CarbonImmutable::now();
        $start = $end->subSeconds($length);

        $sums = [];
        DeviceMetric::query()
            ->where('device_id', $this->selectedDeviceId)
            ->where('created_at', '>=', $start)
            ->orderBy('created_at')
            ->toBase()
            ->lazy()
            ->each(function ($row) use (&$sums, $start, $bucket, $points) {
                $index = min($points - 1, intdiv(CarbonImmutable::parse($row->created_at)->getTimestamp() - $start->getTimestamp(), $bucket));
                $sums[$index]['cpu'][] = (float) $row->cpu;
                $sums[$index]['memory'][] = $row->memory_total > 0 ? $row->memory_used / $row->memory_total * 100 : 0;
            });

        $format = $length >= 86400 ? 'd.m. H:i' : 'H:i';
        $cpu = $memory = $labels = [];
        for ($i = 0; $i < $points; $i++) {
            $cpu[] = isset($sums[$i]) ? round(array_sum($sums[$i]['cpu']) / count($sums[$i]['cpu']), 1) : null;
            $memory[] = isset($sums[$i]) ? round(array_sum($sums[$i]['memory']) / count($sums[$i]['memory']), 1) : null;
            $labels[] = $start->addSeconds(($i + 1) * $bucket)->format($format);
        }

        $latest = DeviceMetric::query()->where('device_id', $this->selectedDeviceId)->latest('created_at')->first();

        return view('livewire.device-metrics', [
            'cpu' => $cpu,
            'memory' => $memory,
            'labels' => $labels,
            'latest' => $latest,
            'from' => $start->format($format),
            'to' => $end->format($format),
        ]);
    }
}
