<?php

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\PicksTarget;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\NotificationSetting;
use App\Support\AlertEvaluator;
use Illuminate\Validation\Rule;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/** Adds or edits one alert rule of the user: type, threshold, minutes and the devices. */
#[AllowInModal]
class RuleForm extends Component
{
    use PicksTarget;

    public ?int $ruleId = null;

    public string $type = 'status';

    public ?int $threshold = null;

    public ?int $minutes = null;

    /** Disk and memory: 'percent' (used above threshold) or 'gb' (free below limitGb). */
    public string $unit = 'percent';

    public ?float $limitGb = null;

    /** The channels it sends to (keys of NotificationSetting::channelOptions). @var array<int, string> */
    public array $channels = [];

    public function mount(?int $ruleId = null): void
    {
        $this->ruleId = $ruleId;
        $rule = $ruleId ? AlertRule::query()->where('user_id', auth()->id())->findOrFail($ruleId) : null;
        if ($rule) {
            $this->type = $rule->type;
            $this->threshold = $rule->threshold;
            $this->minutes = $rule->minutes;
            $this->unit = $rule->unit ?: 'percent';
            $this->limitGb = $rule->limit_gb ?? (AlertRule::DEFAULT_LIMIT_GB[$rule->type] ?? null);
            $this->fillTarget($rule->target);
            $this->channels = $rule->channels ?? array_keys($this->channelOptions());
        } else {
            $this->channels = array_keys($this->channelOptions());
            $this->fillTarget(['all' => true]);
            $this->updatedType();
        }
    }

    /** New type: its default threshold and minutes. */
    public function updatedType(): void
    {
        $defaults = AlertRule::TYPES[$this->type] ?? null;
        if ($defaults === null) {
            return;
        }
        $this->threshold = $defaults['threshold'];
        $this->minutes = $defaults['minutes'];
        $this->unit = 'percent';
        $this->limitGb = AlertRule::DEFAULT_LIMIT_GB[$this->type] ?? null;
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $gb = AlertRule::usesUnit($this->type) && $this->unit === 'gb';
        $this->validate([
            'type' => ['required', Rule::in(array_keys(AlertRule::TYPES))],
            'unit' => ['required', Rule::in(array_keys(AlertRule::UNITS))],
            'threshold' => [AlertRule::usesThreshold($this->type) && ! $gb ? 'required' : 'nullable', 'integer', 'min:1', 'max:99'],
            'limitGb' => [$gb ? 'required' : 'nullable', 'numeric', 'min:0.1', 'max:'.AlertRule::MAX_LIMIT_GB],
            'minutes' => [AlertRule::usesMinutes($this->type) ? 'required' : 'nullable', 'integer', 'min:1', 'max:'.AlertRule::MAX_MINUTES],
        ]);
        if ($this->targetIsEmpty()) {
            $this->addError('target', __('Choose the devices.'));

            return;
        }
        $options = array_keys($this->channelOptions());
        $channels = array_values(array_intersect($options, $this->channels));
        if ($options !== [] && $channels === []) {
            $this->addError('channels', __('Choose where to send it.'));

            return;
        }

        $rule = $this->ruleId ? AlertRule::query()->where('user_id', auth()->id())->findOrFail($this->ruleId) : new AlertRule(['user_id' => auth()->id(), 'enabled' => true]);
        $rule->fill([
            'type' => $this->type,
            'threshold' => AlertRule::usesThreshold($this->type) && ! $gb ? $this->threshold : null,
            'unit' => $gb ? 'gb' : 'percent',
            'limit_gb' => $gb ? round((float) $this->limitGb, 1) : null,
            'minutes' => AlertRule::usesMinutes($this->type) ? $this->minutes : null,
            'target' => $this->target(),
            // All channels (also ones added later) unless some are left out.
            'channels' => count($channels) === count($options) ? null : $channels,
        ]);
        // A changed condition starts over: its open alerts close without a message.
        if ($rule->exists && $rule->isDirty(['type', 'threshold', 'unit', 'limit_gb', 'minutes'])) {
            $rule->events()->whereNull('resolved_at')->update(['resolved_at' => now()]);
        }
        $rule->save();

        $this->dispatch('alertRuleSaved');
        $this->dispatch('closeModal');
    }

    public function delete(): void
    {
        AlertRule::query()->where('user_id', auth()->id())->whereKey($this->ruleId)->delete();
        $this->dispatch('alertRuleSaved');
        $this->dispatch('closeModal');
    }

    /** @return array<string, string> */
    private function channelOptions(): array
    {
        return NotificationSetting::for(auth()->user())->channelOptions;
    }

    /**
     * The devices the alert would fire on right now, as entered (not saved yet).
     *
     * @return array{matching: array<int, string>, checked: int}|null
     */
    private function preview(): ?array
    {
        if ($this->targetIsEmpty() || ! isset(AlertRule::TYPES[$this->type])) {
            return null;
        }
        $gb = AlertRule::usesUnit($this->type) && $this->unit === 'gb';
        $rule = new AlertRule([
            'type' => $this->type,
            'threshold' => $gb ? null : (int) $this->threshold,
            'unit' => $gb ? 'gb' : 'percent',
            'limit_gb' => $gb ? (float) $this->limitGb : null,
            'minutes' => max(1, (int) $this->minutes),
        ]);
        if ((AlertRule::usesThreshold($this->type) && ($gb ? ! $this->limitGb : ! $this->threshold))) {
            return null;
        }
        $evaluator = new AlertEvaluator;
        $devices = Device::targeted($this->target());
        $matching = $devices->filter(fn (Device $device) => ($evaluator->check($rule, $device)['active'] ?? false))->map->displayName->values()->all();

        return ['matching' => $matching, 'checked' => $devices->count()];
    }

    public function render()
    {
        return view('livewire.notifications.rule-form', [
            'channelOptions' => $this->channelOptions(),
            'preview' => $this->preview(),
        ]);
    }
}
