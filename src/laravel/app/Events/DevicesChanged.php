<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something about a device changed (a report, a heartbeat, a command, a ping, a script run, an
 * alert): the portal pages showing it refresh (resources/js/live.js). Only the device id and what
 * changed are sent, the pages load the data themselves.
 */
class DevicesChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public const CHANNEL = 'devices';

    public function __construct(
        public int $deviceId,
        public string $what,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::CHANNEL);
    }

    public function broadcastAs(): string
    {
        return 'changed';
    }

    public function broadcastWith(): array
    {
        return ['device_id' => $this->deviceId, 'what' => $this->what];
    }
}
