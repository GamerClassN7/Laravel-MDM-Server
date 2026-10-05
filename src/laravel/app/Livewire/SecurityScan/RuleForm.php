<?php

namespace App\Livewire\SecurityScan;

use App\Models\Device;
use App\Models\SecurityInventory;
use App\Models\SecurityRule;
use App\Support\SecurityRules;
use App\Support\SecurityScanner;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/**
 * A rule of the scanner in JSON (system admins): checked as it is typed, tried on the last
 * inventory of a device before it is saved. Built-in rules are shown, not edited (a copy can be).
 */
#[AllowInModal]
class RuleForm extends Component
{
    public ?int $ruleId = null;

    public string $json = '';

    public ?int $previewDeviceId = null;

    public bool $readOnly = false;

    private const TEMPLATE = [
        'key' => 'custom.example',
        'name' => 'Example: Chrome older than 120',
        'description' => 'What the rule is about and why it matters.',
        'severity' => 'medium',
        'platform' => 'any',
        'source' => 'software',
        'when' => ['all' => [
            ['field' => 'Name', 'op' => 'starts_with', 'value' => 'google chrome'],
            ['field' => 'Version', 'op' => 'version_lt', 'value' => '120'],
        ]],
        'message' => '{Name} {Version} is outdated',
        'remediation' => 'Update it.',
    ];

    public function mount(?int $ruleId = null, ?int $copyOf = null): void
    {
        Gate::authorize('is-system-admin');
        $definition = self::TEMPLATE;
        if ($ruleId) {
            $rule = SecurityRule::query()->findOrFail($ruleId);
            $this->ruleId = $rule->id;
            $this->readOnly = $rule->built_in;
            $definition = $rule->definition;
        } elseif ($copyOf) {
            $definition = SecurityRule::query()->findOrFail($copyOf)->definition;
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
        $errors = SecurityRules::errors($definition);
        if ($errors === []) {
            $taken = SecurityRule::query()->where('key', $definition['key'])->when($this->ruleId, fn ($q) => $q->whereKeyNot($this->ruleId))->exists();
            if ($taken) {
                $errors[] = __('key: another rule has this key.');
            }
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
        $rule = $this->ruleId ? SecurityRule::query()->where('built_in', false)->findOrFail($this->ruleId) : new SecurityRule(['built_in' => false, 'enabled' => $definition['enabled'] ?? true]);
        $rule->fill(SecurityRule::attributesFor($definition))->save();
        SecurityScanner::scanAll();

        $this->dispatch('securityRuleSaved');
        $this->dispatch('closeModal');
    }

    public function delete(): void
    {
        Gate::authorize('is-system-admin');
        SecurityRule::query()->where('built_in', false)->whereKey($this->ruleId)->delete();
        $this->dispatch('securityRuleSaved');
        $this->dispatch('closeModal');
    }

    public function render()
    {
        ['definition' => $definition, 'errors' => $errors] = $this->parse();
        $device = $this->previewDeviceId ? Device::find($this->previewDeviceId) : null;

        return view('livewire.security-scan.rule-form', [
            'problems' => $errors,
            'preview' => $definition && $device ? SecurityScanner::preview($definition, $device) : null,
            'previewDevice' => $device,
            'devices' => Device::query()->whereIn('id', SecurityInventory::query()->select('device_id'))->get()->sortBy('displayName'),
        ]);
    }
}
