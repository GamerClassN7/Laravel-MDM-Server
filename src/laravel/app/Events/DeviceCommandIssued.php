<?php

namespace App\Events;

use App\Models\Device;
use App\Support\Signing;
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

    /**
     * "command" for agents before 1.7.0. Signing agents only trust "p" signed with the server key
     * ({device_id, ts, command}) and then take their commands over the signed API.
     */
    public function broadcastWith(): array
    {
        $payload = json_encode(['device_id' => $this->device->id, 'ts' => time(), 'command' => $this->command]);

        return ['command' => $this->command, 'p' => $payload, 'sig' => Signing::sign('MDM1-WS', $payload)];
    }
}
