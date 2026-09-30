<?php

namespace App\Livewire\Concerns;

use App\Models\Device;

/** The state of partials/target-picker: all devices, tags or picked devices. */
trait PicksTarget
{
    public bool $targetAll = false;

    /** @var array<int, string> */
    public array $targetTags = [];

    /** @var array<int, string> */
    public array $targetDevices = [];

    protected function fillTarget(?array $target): void
    {
        $target = Device::normalizeTarget($target);
        $this->targetAll = $target['all'];
        $this->targetTags = $target['tags'];
        $this->targetDevices = array_map('strval', $target['devices']);
    }

    protected function target(): array
    {
        return Device::normalizeTarget([
            'all' => $this->targetAll,
            'tags' => $this->targetTags,
            'devices' => $this->targetDevices,
        ]);
    }

    protected function targetIsEmpty(): bool
    {
        $target = $this->target();

        return ! $target['all'] && $target['tags'] === [] && $target['devices'] === [];
    }
}
