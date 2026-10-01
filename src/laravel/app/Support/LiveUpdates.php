<?php

namespace App\Support;

use App\Events\DevicesChanged;
use Laravel\Reverb\Application;
use Laravel\Reverb\Protocols\Pusher\EventDispatcher;
use Throwable;

/** Tells the open portal pages that a device changed (over Reverb). */
class LiveUpdates
{
    /** From a web or API request, or a scheduled task. A Reverb that is down never breaks it. */
    public static function device(int $deviceId, string $what): void
    {
        try {
            DevicesChanged::dispatch($deviceId, $what);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * From inside the Reverb server (agent heartbeats over the WebSocket): published straight to
     * the subscribers, a broadcast over HTTP to the server itself would block its loop.
     */
    public static function fromReverb(Application $app, int $deviceId, string $what): void
    {
        $event = new DevicesChanged($deviceId, $what);
        EventDispatcher::dispatch($app, [
            'event' => $event->broadcastAs(),
            'channel' => 'private-'.DevicesChanged::CHANNEL,
            'data' => json_encode($event->broadcastWith()),
        ]);
    }
}
