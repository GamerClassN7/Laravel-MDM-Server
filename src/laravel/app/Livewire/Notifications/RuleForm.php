<?php

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\PicksTarget;
use App\Models\AlertRule;
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
        } else {
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

        $rule = $this->ruleId ? AlertRule::query()->where('user_id', auth()->id())->findOrFail($this->ruleId) : new AlertRule(['user_id' => auth()->id(), 'enabled' => true]);
        $rule->fill([
            'type' => $this->type,
            'threshold' => AlertRule::usesThreshold($this->type) && ! $gb ? $this->threshold : null,
            'unit' => $gb ? 'gb' : 'percent',
            'limit_gb' => $gb ? round((float) $this->limitGb, 1) : null,
            'minutes' => AlertRule::usesMinutes($this->type) ? $this->minutes : null,
            'target' => $this->target(),
        ]);
        // A changed condition starts over: its open alerts close without a message.
        if ($rule->exists && $rule->isDirty(['type', 'threshold', 'unit', 'limit_gb', 'minutes'])) {
            $rule->events()->whereNull('resolved_at')->update(['resolved_at' => now()]);
        }
        $rule->save();

        $this->dispatch('alertRuleSaved');
        $this->dispatch('closeModal');
    }

    public function render()
    {
        return view('livewire.notifications.rule-form');
    }
}
