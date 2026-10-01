<?php

namespace App\Livewire\Concerns;

use App\Models\Device;

/** The state of partials/target-picker: all devices, the devices with any of the tags, or picked devices. */
trait PicksTarget
{
    /** all, tags or devices */
    public string $targetMode = 'all';

    /** @var array<int, string> */
    public array $targetTags = [];

    /** @var array<int, string> */
    public array $targetDevices = [];

    protected function fillTarget(?array $target): void
    {
        $target = Device::normalizeTarget($target);
        $this->targetTags = $target['tags'];
        $this->targetDevices = array_map('strval', $target['devices']);
        $this->targetMode = match (true) {
            $target['all'] => 'all',
            $target['tags'] === [] && $target['devices'] !== [] => 'devices',
            $target['tags'] !== [] => 'tags',
            default => 'all',
        };
    }

    /** Only what the chosen mode shows counts. */
    protected function target(): array
    {
        return Device::normalizeTarget([
            'all' => $this->targetMode === 'all',
            'tags' => $this->targetMode === 'tags' ? $this->targetTags : [],
            'devices' => $this->targetMode === 'devices' ? $this->targetDevices : [],
        ]);
    }

    protected function targetIsEmpty(): bool
    {
        $target = $this->target();

        return ! $target['all'] && $target['tags'] === [] && $target['devices'] === [];
    }
}
