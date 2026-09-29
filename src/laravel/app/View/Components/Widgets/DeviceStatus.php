<?php

namespace App\View\Components\Widgets;

use App\Models\Device;
use Illuminate\View\Component;

/**
 * Dashboard widget: how many devices are online right now (heartbeat within
 * Device::HEARTBEAT_TIMEOUT) and which ones are offline. Live, not an hourly statistic.
 */
class DeviceStatus extends Component
{
    public $help = 'Devices online and offline right now, with the offline ones listed.';

    public function __construct(
        public array $config = [],
        public $widgetId = null,
    ) {
        $this->config = array_merge($this->default(), array_intersect_key($config, $this->default()));
    }

    public function default(): array
    {
        return [
            'name' => __('Devices'),
            'description' => __('Online and offline right now'),
            // How many offline devices to list (0 hides the list).
            'list' => '5',
        ];
    }

    public function render()
    {
        $devices = Device::all();
        $offline = $devices->filter(fn (Device $device) => $device->offline)
            ->sortByDesc(fn (Device $device) => $device->last_seen_at ?? $device->updated_at)
            ->values();

        return view('components.widgets.device-status', [
            'total' => $devices->count(),
            'online' => $devices->count() - $offline->count(),
            'offline' => $offline,
            'listed' => $offline->take(max(0, (int) $this->config['list'])),
        ]);
    }
}
