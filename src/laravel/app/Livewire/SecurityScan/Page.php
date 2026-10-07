<?php

namespace App\Livewire\SecurityScan;

use App\Jobs\SyncSecurityFeed;
use App\Models\CompliancePolicy;
use App\Models\ComplianceResult;
use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Models\SecurityInventory;
use App\Models\SecurityRule;
use App\Support\CompliancePolicies;
use App\Support\ComplianceScanner;
use App\Support\SecurityFeed;
use App\Support\SecurityInbox;
use App\Support\SecurityRules;
use App\Support\SecurityScanner;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The security scanner of the fleet: the findings of its rules on the devices' security
 * inventories (acknowledge, open again), the events the agents saw (a small SIEM) and the rules
 * (system admins switch them, add their own in JSON and scan again).
 */
class Page extends Component
{
    /** findings, events, compliance or rules */
    #[Url(except: 'findings')]
    public string $tab = 'findings';

    /** open (needs attention), acknowledged or resolved */
    #[Url(except: 'open')]
    public string $status = 'open';

    #[Url(except: '')]
    public string $severity = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $eventType = '';

    /** Compliance: the policy whose checks are shown (its id), 0 for the list of policies. */
    #[Url(except: 0)]
    public int $policy = 0;

    /** Findings and events shown at most. */
    public const LIMIT = 200;

    public function mount(): void
    {
        SecurityRule::syncBuiltIn();
    }

    public function acknowledge(int $id): void
    {
        SecurityFinding::query()->with('rule')->findOrFail($id)->acknowledge(auth()->user());
    }

    public function reopen(int $id): void
    {
        SecurityFinding::query()->with('rule')->findOrFail($id)->reopen();
    }

    /** Acknowledges what the page shows (the filters apply). */
    public function acknowledgeAll(): void
    {
        foreach ($this->findings()->whereNull('acknowledged_at')->get() as $finding) {
            $finding->acknowledge(auth()->user());
        }
    }

    public function toggleRule(int $id): void
    {
        Gate::authorize('is-system-admin');
        $rule = SecurityRule::query()->findOrFail($id);
        $rule->update(['enabled' => ! $rule->enabled]);
        // A parser takes effect with the next collections, a detection rule now.
        if (! $rule->isParser) {
            SecurityScanner::scanAll();
        }
    }

    public function addRule(): void
    {
        Gate::authorize('is-system-admin');
        $this->dispatch('openModal', 'security-scan.rule-form', __('Add rule'), [], 'xl');
    }

    public function editRule(int $id): void
    {
        Gate::authorize('is-system-admin');
        $this->dispatch('openModal', 'security-scan.rule-form', __('Edit rule'), ['ruleId' => $id], 'xl');
    }

    public function addParser(): void
    {
        Gate::authorize('is-system-admin');
        $this->dispatch('openModal', 'security-scan.rule-form', __('Add parser'), ['kind' => 'parser'], 'xl');
    }

    /** A copy of a rule (a built-in one cannot be edited) to change as one's own. */
    public function duplicateRule(int $id): void
    {
        Gate::authorize('is-system-admin');
        $this->dispatch('openModal', 'security-scan.rule-form', __('Add rule'), ['copyOf' => $id], 'xl');
    }

    /** Takes the rules of the feed now (a job: it waits for the queue worker). */
    public function updateFeed(): void
    {
        Gate::authorize('is-system-admin');
        if (! SecurityFeed::enabled()) {
            alert()->warning(__('No rules feed is set (MDM_SECURITY_FEED_URL).'))->now();

            return;
        }
        SyncSecurityFeed::dispatch(true);
        alert()->success(__('Updating the rules.'))->now();
    }

    public function rescan(): void
    {
        Gate::authorize('is-system-admin');
        $counts = SecurityScanner::scanAll();
        alert()->success(__('Scanned: :opened new, :resolved resolved.', $counts))->now();
    }

    public function togglePolicy(int $id): void
    {
        Gate::authorize('is-system-admin');
        $policy = CompliancePolicy::query()->findOrFail($id);
        $policy->update(['enabled' => ! $policy->enabled]);
        ComplianceScanner::evaluateAll();
    }

    public function addPolicy(): void
    {
        Gate::authorize('is-system-admin');
        $this->dispatch('openModal', 'security-scan.policy-form', __('Add policy'), [], 'xl');
    }

    public function editPolicy(int $id): void
    {
        Gate::authorize('is-system-admin');
        $this->dispatch('openModal', 'security-scan.policy-form', __('Edit policy'), ['policyId' => $id], 'xl');
    }

    /** A copy of a policy (one of the feed cannot be edited) to change as one's own. */
    public function duplicatePolicy(int $id): void
    {
        Gate::authorize('is-system-admin');
        $this->dispatch('openModal', 'security-scan.policy-form', __('Add policy'), ['copyOf' => $id], 'xl');
    }

    public function reevaluate(): void
    {
        Gate::authorize('is-system-admin');
        ComplianceScanner::evaluateAll();
        alert()->success(__('Compliance evaluated again.'))->now();
    }

    #[On('securityRuleSaved')]
    #[On('compliancePolicySaved')]
    public function refresh(): void {}

    /** Live update (resources/js/live.js): some device changed. */
    #[On('devices-changed')]
    public function devicesChanged(): void {}

    private function findings()
    {
        $query = SecurityFinding::query()->with(['device', 'rule', 'acknowledger']);
        match ($this->status) {
            'acknowledged' => $query->whereNotNull('acknowledged_at'),
            'resolved' => $query->whereNotNull('resolved_at')->whereNull('acknowledged_at'),
            default => $query->active(),
        };
        if (isset(SecurityRules::SEVERITIES[$this->severity])) {
            $query->where('severity', $this->severity);
        }
        if (($search = trim($this->search)) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('message', 'like', $like)
                ->orWhereHas('device', fn ($device) => $device->where('name', 'like', $like)->orWhere('friendly_name', 'like', $like))
                ->orWhereHas('rule', fn ($rule) => $rule->where('name', 'like', $like)));
        }

        return $query;
    }

    /**
     * The compliance tab: the policies with the statuses of their results over the fleet, or the
     * checks of one policy with the devices that do not pass.
     */
    private function compliance(): array
    {
        $policies = CompliancePolicy::query()->orderBy('name')->get();
        $counts = ComplianceResult::query()->selectRaw('compliance_policy_id, status, count(*) as total')->groupBy('compliance_policy_id', 'status')->get()
            ->groupBy('compliance_policy_id')->map(fn ($rows) => $rows->pluck('total', 'status')->map(fn ($total) => (int) $total)->all());
        $devices = ComplianceResult::query()->selectRaw('compliance_policy_id, count(distinct device_id) as total')->groupBy('compliance_policy_id')->pluck('total', 'compliance_policy_id');
        $selected = $this->policy ? $policies->firstWhere('id', $this->policy) : null;
        $checks = [];
        if ($selected !== null) {
            $results = ComplianceResult::query()->with('device')->where('compliance_policy_id', $selected->id)->get()->groupBy('check_id');
            foreach ($selected->checks() as $id => $check) {
                $rows = $results->get($id, collect());
                $checks[$id] = [
                    'check' => $check,
                    'counts' => ComplianceScanner::counts($rows),
                    'open' => $rows->whereIn('status', [CompliancePolicies::FAIL, CompliancePolicies::WARN, CompliancePolicies::MANUAL])
                        ->sortBy(fn ($row) => CompliancePolicies::STATUSES[$row->status])->take(self::LIMIT)->values(),
                ];
            }
        }

        return [
            'policies' => $policies,
            'policyCounts' => $counts,
            'policyDevices' => $devices,
            'selectedPolicy' => $selected,
            'checks' => $checks,
        ];
    }

    public function render()
    {
        $active = SecurityFinding::query()->active()->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity');
        $events = collect();
        if ($this->tab === 'events') {
            $events = SecurityEvent::query()->with('device')->latest('occurred_at')->latest('id')
                ->when($this->eventType !== '', fn ($q) => $q->where('type', $this->eventType))
                ->limit(self::LIMIT)->get();
        }

        return view('livewire.security-scan.page', [
            'counts' => collect(SecurityRules::SEVERITIES)->map(fn ($rank, $severity) => (int) ($active[$severity] ?? 0)),
            'findings' => $this->tab === 'findings' ? $this->findings()->bySeverity()->limit(self::LIMIT)->get() : collect(),
            'events' => $events,
            'rules' => $this->tab === 'rules' ? SecurityRule::query()->detection()->addSelect(['open_count' => SecurityFinding::query()->active()->selectRaw('count(distinct device_id)')->whereColumn('security_rule_id', 'security_rules.id')])->orderBy('source')->orderBy('name')->get() : collect(),
            'parsers' => $this->tab === 'rules' ? SecurityRule::query()->parsers()->orderBy('source')->orderBy('name')->get() : collect(),
            'eventTypes' => $this->tab === 'events' ? SecurityEvent::query()->distinct()->orderBy('type')->pluck('type')->mapWithKeys(fn ($type) => [$type => (new SecurityEvent(['type' => $type]))->label]) : collect(),
            'pending' => SecurityInbox::pending(),
            'feed' => ['enabled' => SecurityFeed::enabled()] + SecurityFeed::state(),
            'scanned' => SecurityInventory::query()->count(),
            'compliance' => $this->tab === 'compliance' ? $this->compliance() : null,
            'isAdmin' => Gate::allows('is-system-admin'),
        ])->title(__('Security'));
    }
}
