<?php

namespace App\Livewire\SecurityScan;

use App\Models\CompliancePolicy;
use App\Models\Device;
use App\Models\SecurityInventory;
use App\Support\CompliancePolicies;
use App\Support\ComplianceScanner;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/**
 * A compliance policy in JSON (system admins): checked as it is typed and tried on the last
 * inventory of a device before it is saved. Policies of the feed are shown, not edited (a copy can be).
 */
#[AllowInModal]
class PolicyForm extends Component
{
    public ?int $policyId = null;

    public string $json = '';

    public ?int $previewDeviceId = null;

    public bool $readOnly = false;

    private const TEMPLATE = [
        'key' => 'custom.windows-baseline',
        'name' => 'Example: Windows baseline',
        'description' => 'What the policy is about.',
        'version' => '1.0',
        'platform' => 'windows',
        'source' => 'posture',
        'checks' => [
            [
                'id' => 'Win.FirewallEnabled',
                'name' => 'The firewall is on in every profile',
                'reference' => 'internal 1.1',
                'severity' => 'high',
                'fail' => ['field' => 'FirewallEnabled', 'op' => 'false'],
                'message' => 'The firewall is off',
                'remediation' => 'Turn the firewall on.',
            ],
            [
                'id' => 'Win.UpdatesRecent',
                'name' => 'Updates installed in the last 30 days',
                'severity' => 'medium',
                'fail' => ['field' => 'DaysSinceUpdate', 'op' => 'gt', 'value' => 60],
                'warn' => ['field' => 'DaysSinceUpdate', 'op' => 'gt', 'value' => 30],
                'message' => 'Last update {DaysSinceUpdate} days ago',
            ],
        ],
    ];

    public function mount(?int $policyId = null, ?int $copyOf = null): void
    {
        Gate::authorize('is-system-admin');
        $definition = self::TEMPLATE;
        if ($policyId) {
            $policy = CompliancePolicy::query()->findOrFail($policyId);
            $this->policyId = $policy->id;
            $this->readOnly = $policy->built_in;
            $definition = $policy->definition;
        } elseif ($copyOf) {
            $definition = CompliancePolicy::query()->findOrFail($copyOf)->definition;
            $definition['key'] = mb_substr('custom.'.preg_replace('/^custom\./', '', $definition['key']), 0, 57).'-copy';
            $definition['name'] = mb_strimwidth($definition['name'], 0, 112).' (copy)';
            unset($definition['enabled']);
        }
        $this->json = json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->previewDeviceId = SecurityInventory::query()->latest('collected_at')->value('device_id');
    }

    /** @return array{definition: ?array, errors: array<int, string>} */
    private function parse(): array
    {
        $definition = json_decode($this->json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['definition' => null, 'errors' => [__('Not valid JSON: :error.', ['error' => json_last_error_msg()])]];
        }
        $errors = CompliancePolicies::errors($definition);
        if ($errors === [] && CompliancePolicy::query()->where('key', $definition['key'])->when($this->policyId, fn ($q) => $q->whereKeyNot($this->policyId))->exists()) {
            $errors[] = __('key: another policy has this key.');
        }

        return ['definition' => $errors === [] ? $definition : null, 'errors' => $errors];
    }

    public function save(): void
    {
        Gate::authorize('is-system-admin');
        if ($this->readOnly) {
            return;
        }
        ['definition' => $definition, 'errors' => $errors] = $this->parse();
        if ($definition === null) {
            $this->addError('json', $errors[0]);

            return;
        }
        $policy = $this->policyId ? CompliancePolicy::query()->custom()->findOrFail($this->policyId) : new CompliancePolicy(['origin' => CompliancePolicy::CUSTOM, 'enabled' => $definition['enabled'] ?? true]);
        $policy->fill(CompliancePolicy::attributesFor($definition))->save();
        ComplianceScanner::evaluateAll();

        $this->dispatch('compliancePolicySaved');
        $this->dispatch('closeModal');
    }

    public function delete(): void
    {
        Gate::authorize('is-system-admin');
        CompliancePolicy::query()->custom()->whereKey($this->policyId)->delete();
        $this->dispatch('compliancePolicySaved');
        $this->dispatch('closeModal');
    }

    public function render()
    {
        ['definition' => $definition, 'errors' => $errors] = $this->parse();
        $device = $this->previewDeviceId ? Device::find($this->previewDeviceId) : null;

        return view('livewire.security-scan.policy-form', [
            'problems' => $errors,
            'preview' => $definition && $device ? ComplianceScanner::preview($definition, $device) : null,
            'previewDevice' => $device,
            'devices' => Device::query()->whereIn('id', SecurityInventory::query()->select('device_id'))->get()->sortBy('displayName'),
        ]);
    }
}
