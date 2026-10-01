<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\PingResult;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The monitor of a ping-only device, in the style of Uptime Kuma: the last checks as a bar, the
 * uptime and the response time over the chosen range.
 */
class PingMonitor extends Component
{
    public int $deviceId;

    public string $range = '24h';

    public const RANGES = ['1h' => 1, '24h' => 24, '7d' => 168, '30d' => 720];

    /** Checks in the bar (every 30 s: the last 25 minutes). */
    public const BEATS = 50;

    /** Points of the response time chart. */
    private const BUCKETS = 48;

    public function setRange(string $range): void
    {
        if (isset(self::RANGES[$range])) {
            $this->range = $range;
        }
    }

    #[On('ping-settings-saved')]
    public function refresh(): void {}

    /** Live update (resources/js/live.js): this device changed. */
    #[On('device-changed.{deviceId}')]
    public function deviceChanged(): void {}

    public function render()
    {
        $device = Device::findOrFail($this->deviceId);
        $hours = self::RANGES[$this->range];
        $since = now()->subHours($hours);

        $beats = PingResult::query()->where('device_id', $device->id)->latest('id')->limit(self::BEATS)->get()->reverse()->values();

        // Response time: the average of each bucket, and whether a ping went unanswered in it.
        $bucketSeconds = $hours * 3600 / self::BUCKETS;
        $buckets = array_fill(0, self::BUCKETS, ['sum' => 0.0, 'count' => 0, 'down' => false]);
        PingResult::query()->where('device_id', $device->id)->where('created_at', '>=', $since)
            ->orderBy('id')->get(['up', 'rtt', 'created_at'])
            ->each(function (PingResult $result) use (&$buckets, $since, $bucketSeconds) {
                $index = min(self::BUCKETS - 1, (int) floor(($result->created_at->getTimestamp() - $since->getTimestamp()) / $bucketSeconds));
                if ($index < 0) {
                    return;
                }
                if (! $result->up) {
                    $buckets[$index]['down'] = true;
                } elseif ($result->rtt !== null) {
                    $buckets[$index]['sum'] += $result->rtt;
                    $buckets[$index]['count']++;
                }
            });
        $points = array_map(fn ($bucket) => $bucket['count'] > 0 ? round($bucket['sum'] / $bucket['count'], 1) : null, $buckets);
        $answered = PingResult::query()->where('device_id', $device->id)->where('created_at', '>=', $since)->where('up', true);

        return view('livewire.ping-monitor', [
            'device' => $device,
            'relay' => $device->pingRelay(),
            'beats' => $beats,
            'points' => $points,
            'downs' => array_map(fn ($bucket) => $bucket['down'], $buckets),
            'max' => max(1, (float) max(array_filter($points, fn ($point) => $point !== null) ?: [1])),
            'from' => $since,
            'stats' => [
                'current' => $device->offline ? null : $device->ping_rtt,
                'average' => ($average = (clone $answered)->avg('rtt')) === null ? null : round((float) $average, 1),
                'uptime24' => PingResult::uptime($device->id, now()->subDay()),
                'uptime30' => PingResult::uptime($device->id, now()->subDays(30)),
            ],
        ]);
    }
}
