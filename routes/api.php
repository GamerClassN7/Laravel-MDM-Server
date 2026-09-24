<?php

use App\Models\Device;
use App\Models\Enrolment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::middleware('auth:api')->post('/device', function (Request $request) {
    /** @var Device $device */
    $device = auth()->user();

    if ($device === null) {
        return;
    }

    $data = json_decode($request->getContent(), true);
    Log::info($data);

    if ($data === null) {
        return;
    }

    // //whether ip is from the remote address
    // $ip = $_SERVER['REMOTE_ADDR'];
    // //whether ip is from the share internet
    // if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    //     $ip = $_SERVER['HTTP_CLIENT_IP'];
    // }
    // //whether ip is from the proxy
    // elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    //     $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    // }

    $device->drives = $data['machine']['Drives'];
    //$device->public_ip = $ip;
    //$table->ipAddress('public_ip');
    $device->name = $data['machine']['Hostname'];
    $device->os = $data['machine']['os'] ?? '';

    $device->data = json_encode($data);

    $commands = $device->commands;

    $device->commands = [];
    $device->save();

    return response()->json([
        'commands' => $commands,
    ]);
});

Route::post('/device/register', function (Request $request) {
    $data = $request->json()->all();
    Log::error($data);
    $inviteCode = $data['enrolment_code'];
    $invitation = Enrolment::where('code', $inviteCode)->where('expire_at', '>', CarbonImmutable::now())->first();

    if (null === $invitation) {
        return "invalid token";
    }

    $token = Str::random(60);

    $device = new Device();
    $device->token = hash('sha256', $token);
    $device->save();

    $invitation->delete();

    return response()->json([
        'token' => $token,
    ]);
});

Route::middleware('auth:api')->group(function () {
    // Connection details for the agent's WebSocket (Reverb / Pusher protocol) client.
    Route::get('/device/realtime', function (Request $request) {
        /** @var Device $device */
        $device = $request->user();

        if (config('broadcasting.default') !== 'reverb') {
            return response()->json(['enabled' => false]);
        }

        $options = config('broadcasting.connections.reverb.options');

        return response()->json([
            'enabled' => true,
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => $options['host'],
            'port' => (int) $options['port'],
            'scheme' => $options['scheme'],
            'path' => config('reverb.servers.reverb.path', ''),
            'channel' => 'private-device.'.$device->id,
            'auth_url' => url('/api/broadcasting/auth'),
        ]);
    });

    // The agent acknowledges a command before executing it so it is not delivered twice.
    Route::post('/device/commands/ack', function (Request $request) {
        /** @var Device $device */
        $device = $request->user();
        $command = $request->input('command');

        $device->commands = array_values(array_diff($device->commands ?? [], [$command]));
        $device->save();

        return response()->json(['commands' => $device->commands]);
    });
});
