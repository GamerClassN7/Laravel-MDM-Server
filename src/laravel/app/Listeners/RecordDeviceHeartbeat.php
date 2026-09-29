<?php

namespace App\Listeners;

use App\Models\Device;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Throwable;

/**
 * Runs inside the Reverb server: records "client-heartbeat" events agents send on their channel.
 */
class RecordDeviceHeartbeat
{
    public function __construct(
        private ChannelManager $channels,
    ) {}

    public function handle(MessageReceived $event): void
    {
        $message = json_decode($event->message, true);

        if (($message['event'] ?? null) !== 'client-heartbeat') {
            return;
        }

        if (! preg_match('/^private-device\.(\d+)$/', $message['channel'] ?? '', $matches)) {
            return;
        }

        // Only a connection that passed channel authorization may report for the device.
        $channel = $this->channels->for($event->connection->app())->find($message['channel']);
        if (! $channel?->find($event->connection)) {
            return;
        }

        try {
            Device::recordHeartbeat((int) $matches[1], $message['data'] ?? null, 'ws');
        } catch (Throwable $e) {
            // Reverb swallows listener exceptions (its logger is off without --debug), report them
            // to the application log so they show up next to the web errors.
            report($e);
        }
    }
}
