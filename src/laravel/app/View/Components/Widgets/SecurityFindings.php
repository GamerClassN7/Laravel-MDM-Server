<?php

namespace App\View\Components\Widgets;

use App\Models\SecurityFinding;
use App\Support\SecurityRules;
use Illuminate\View\Component;

/**
 * Dashboard widget: the open security findings nobody acknowledged (what the Security page lists as
 * open), counted per severity and the most severe ones listed, linking to the Security page.
 */
class SecurityFindings extends Component
{
    public $help = 'Open security findings per severity, the most severe ones listed. Severity: critical, high, medium, low or info.';

    public function __construct(
        public array $config = [],
        public $widgetId = null,
    ) {
        $this->config = array_merge($this->default(), array_intersect_key($config, $this->default()));
    }

    public function default(): array
    {
        return [
            'name' => __('Security findings'),
            'description' => __('Open findings of the security scanner'),
            // Least severe finding listed (the counts always show every severity).
            'severity' => 'medium',
            // How many findings to list (0 hides the list).
            'list' => '5',
        ];
    }

    public function render()
    {
        $severity = array_key_exists($this->config['severity'], SecurityRules::SEVERITIES) ? $this->config['severity'] : 'medium';
        $active = SecurityFinding::query()->active()->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity');
        $limit = max(0, (int) $this->config['list']);

        return view('components.widgets.security-findings', [
            'counts' => collect(SecurityRules::SEVERITIES)->map(fn ($rank, $level) => (int) ($active[$level] ?? 0)),
            'listed' => $limit === 0 ? collect() : SecurityFinding::query()->with(['device', 'rule'])
                ->active()->atLeast($severity)->bySeverity()->limit($limit)->get(),
            'listedSeverity' => $severity,
        ]);
    }
}
