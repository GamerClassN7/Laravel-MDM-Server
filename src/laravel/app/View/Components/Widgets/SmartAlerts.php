<?php

namespace App\View\Components\Widgets;

use App\Support\SmartAlerts as Alerts;
use Illuminate\View\Component;

/**
 * Dashboard widget: the smart alerts of all devices with their actions (restart, update the
 * agent, install updates ...), also for all devices at once.
 */
class SmartAlerts extends Component
{
    public $help = 'What needs attention on all devices, with the action that fixes it. Severity: danger, warning, info or secondary (includes offline devices).';

    public function __construct(
        public array $config = [],
        public $widgetId = null,
    ) {
        $this->config = array_merge($this->default(), array_intersect_key($config, $this->default()));
    }

    public function default(): array
    {
        return [
            'name' => __('Smart alerts'),
            'description' => __('What needs attention on all devices'),
            // Least severe alert shown.
            'severity' => 'warning',
            // How many alerts to list.
            'list' => '10',
        ];
    }

    public function render()
    {
        return view('components.widgets.smart-alerts', [
            'severity' => array_key_exists($this->config['severity'], Alerts::SEVERITIES) ? $this->config['severity'] : 'warning',
            'limit' => max(1, (int) $this->config['list']),
        ]);
    }
}
