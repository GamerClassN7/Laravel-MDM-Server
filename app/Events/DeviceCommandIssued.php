<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DeviceCommandIssued implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public Device $device,
        public string $command,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('device.'.$this->device->id);
    }

    public function broadcastAs(): string
    {
        return 'command';
    }

    public function broadcastWith(): array
    {
        return ['command' => $this->command];
    }
}
