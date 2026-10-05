<?php

namespace App\Livewire\SecurityScan;

use App\Models\Device;
use App\Models\SecurityInventory;
use App\Models\SecurityRule;
use App\Support\SecurityParsers;
use App\Support\SecurityScanner;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/**
 * A rule of the scanner in JSON (system admins): checked as it is typed, tried before it is saved
 * (a detection rule on the last inventory of a device, a parser on a sample record). Built-in
 * ones are shown, not edited (a copy can be).
 */
#[AllowInModal]
class RuleForm extends Component
{
    public ?int $ruleId = null;

    public string $json = '';

    public ?int $previewDeviceId = null;

    public bool $readOnly = false;

    /** Parsers: a record to try it on, a log line or a record in JSON. */
    public string $sample = '';

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

    private const PARSER_TEMPLATE = [
        'kind' => 'parser',
        'key' => 'custom.su-failed',
        'name' => 'su: wrong password',
        'source' => 'linux.auth',
        'when' => ['field' => 'Identifier', 'op' => 'eq', 'value' => 'su'],
        'pattern' => '^FAILED SU \\(to (?<target>\\S+)\\) (?<user>\\S+) on',
        'event' => ['type' => 'su_failed', 'user' => '{user}', 'source' => '{target}', 'message' => '{user} failed su to {target}'],
    ];

    private const PARSER_SAMPLE = '{"Time": "2026-10-05T10:00:00Z", "Identifier": "su", "Pid": 123, "Message": "FAILED SU (to root) alice on pts/0"}';

    public function mount(?int $ruleId = null, ?int $copyOf = null, string $kind = 'detection'): void
    {
        Gate::authorize('is-system-admin');
        $definition = $kind === 'parser' ? self::PARSER_TEMPLATE : self::TEMPLATE;
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
        $this->sample = ($definition['kind'] ?? null) === 'parser' ? self::PARSER_SAMPLE : '';
    }

    /** @return array{definition: ?array, errors: array<int, string>} */
    private function parse(): array
    {
        $definition = json_decode($this->json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['definition' => null, 'errors' => [__('Not valid JSON: :error.', ['error' => json_last_error_msg()])]];
        }
        $errors = SecurityRule::errorsOf($definition);
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
        $rule = $this->ruleId ? SecurityRule::query()->custom()->findOrFail($this->ruleId) : new SecurityRule(['origin' => SecurityRule::CUSTOM, 'enabled' => $definition['enabled'] ?? true]);
        $rule->fill(SecurityRule::attributesFor($definition))->save();
        if (! $rule->isParser) {
            SecurityScanner::scanAll();
        }

        $this->dispatch('securityRuleSaved');
        $this->dispatch('closeModal');
    }

    public function delete(): void
    {
        Gate::authorize('is-system-admin');
        SecurityRule::query()->custom()->whereKey($this->ruleId)->delete();
        $this->dispatch('securityRuleSaved');
        $this->dispatch('closeModal');
    }

    /** The event the parser makes of the sample: ['event' => ?array] or ['error' => string]. */
    private function trySample(array $parser): array
    {
        $sample = trim($this->sample);
        if ($sample === '') {
            return ['error' => __('Enter a sample record.')];
        }
        $record = str_starts_with($sample, '{') ? json_decode($sample, true) : ['Message' => $sample];
        if (! is_array($record)) {
            return ['error' => __('The sample is not valid JSON.')];
        }

        return ['event' => SecurityParsers::apply($parser, $record)];
    }

    public function render()
    {
        ['definition' => $definition, 'errors' => $errors] = $this->parse();
        $isParser = is_array($json = json_decode($this->json, true)) && ($json['kind'] ?? null) === 'parser';
        $device = $this->previewDeviceId ? Device::find($this->previewDeviceId) : null;

        return view('livewire.security-scan.rule-form', [
            'problems' => $errors,
            'isParser' => $isParser,
            'parsed' => $isParser && $definition ? $this->trySample($definition) : null,
            'preview' => ! $isParser && $definition && $device ? SecurityScanner::preview($definition, $device) : null,
            'previewDevice' => $device,
            'devices' => Device::query()->whereIn('id', SecurityInventory::query()->select('device_id'))->get()->sortBy('displayName'),
        ]);
    }
}
