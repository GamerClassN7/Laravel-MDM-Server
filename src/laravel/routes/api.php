<?php

use App\Models\Device;
use App\Models\Enrolment;
use App\Models\ScriptRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Every device endpoint is signed in both directions (App\Http\Middleware\DeviceSignature).
Route::middleware(['device.signature', 'auth:api'])->post('/device', function (Request $request) {
    /** @var Device $device */
    $device = auth()->user();

    if ($device === null) {
        return;
    }

    $data = json_decode($request->getContent(), true);

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
    $device->last_http_at = now();
    // The commands column is not written here: queued commands are taken atomically below.
    $device->save();

    return response()->json([
        // Commands not delivered over the WebSocket; queued meanwhile ones wait for the next report.
        'commands' => Device::takeCommands($device->id),
        // Script runs queued while the device was offline.
        'scripts_pending' => $device->signsRequests && ScriptRun::query()->where('device_id', $device->id)->where('status', 'pending')->exists(),
    ]);
});

Route::middleware('device.signature')->post('/device/register', function (Request $request) {
    $invitation = Enrolment::where('code', (string) $request->json('enrolment_code'))->where('expire_at', '>', CarbonImmutable::now())->first();

    if (null === $invitation) {
        return response()->json(['error' => 'invalid_enrolment_code'], 422);
    }

    $token = Str::random(60);

    $device = new Device();
    $device->token = hash('sha256', $token);
    // Agents 1.7.0+ send their public key, the request is signed with it (checked by the middleware).
    if ($key = $request->attributes->get('mdm_public_key')) {
        $device->public_key = $key;
        $device->key_registered_at = now();
    }
    $device->save();

    $invitation->delete();
    $request->attributes->set('mdm_device_id', $device->id);

    return response()->json([
        'token' => $token,
        'device_id' => $device->id,
    ]);
});

Route::middleware(['device.signature', 'auth:api'])->group(function () {
    // Connection details for the agent's WebSocket (Reverb / Pusher protocol) client.
    Route::get('/device/realtime', function (Request $request) {
        /** @var Device $device */
        $device = $request->user();

        if (config('broadcasting.default') !== 'reverb') {
            return response()->json(['enabled' => false]);
        }

        $options = config('broadcasting.connections.reverb.options');
        $host = $options['host'] ?? null;
        $port = (int) ($options['port'] ?? 0);
        $scheme = $options['scheme'] ?? 'https';

        // REVERB_HOST is also where the app publishes events. When it is local (the Docker image
        // publishes to Reverb inside the container) or not set, agents connect to the address they
        // reach this server on: nginx in front of the app proxies /app to Reverb.
        if (blank($host) || in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0'], true)) {
            // Behind a TLS proxy this is http on port 80; the agent switches to 443 because its
            // -ServerUrl is https.
            $host = $request->getHost();
            $scheme = $request->getScheme();
            $port = (int) $request->getPort();
        }

        return response()->json([
            'enabled' => true,
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
            'path' => config('reverb.servers.reverb.path', ''),
            'channel' => 'private-device.'.$device->id,
            'auth_url' => url('/api/broadcasting/auth'),
        ]);
    });

    // Heartbeat fallback for agents without a WebSocket connection.
    Route::post('/device/heartbeat', function (Request $request) {
        Device::recordHeartbeat($request->user()->id, $request->input('metrics'), 'http', $request->input('state'));

        return response()->noContent();
    });

    // A device updated to a signing agent registers its key once (trust on first use, with the
    // device token). The request is signed with that key; a registered key is only replaced after
    // an admin resets it.
    Route::post('/device/key', function (Request $request) {
        /** @var Device $device */
        $device = $request->user();
        if ($device->public_key !== null) {
            return response()->json(['error' => 'key_already_registered'], 409);
        }
        $key = $request->attributes->get('mdm_public_key');
        if ($key === null) {
            return response()->json(['error' => 'signature_required'], 401);
        }

        $updated = Device::query()->whereKey($device->id)->whereNull('public_key')->toBase()
            ->update(['public_key' => json_encode($key), 'key_registered_at' => now()]);

        return $updated === 1
            ? response()->json(['device_id' => $device->id])
            : response()->json(['error' => 'key_already_registered'], 409);
    });

    // Signing agents take their commands here when the WebSocket announces one: the response is
    // signed for this request, so a command cannot be replayed or injected.
    Route::post('/device/commands/take', function (Request $request) {
        return response()->json(['commands' => Device::takeCommands($request->user()->id)]);
    });

    // Remediation scripts waiting for this device, each with its manifest signed with the server
    // key (for this device and run, valid for 24 hours). Taking them marks them sent.
    Route::get('/device/scripts', function (Request $request) {
        /** @var Device $device */
        $device = $request->user();
        abort_unless($device->signsRequests, 403);

        $runs = ScriptRun::query()->with('script')->where('device_id', $device->id)->where('status', 'pending')->orderBy('id')->limit(20)->get();
        $payloads = [];
        foreach ($runs as $run) {
            if ($run->expires_at->isPast()) {
                $run->update(['status' => 'expired']);
                continue;
            }
            // The script changed after the run was issued: that version cannot be sent anymore.
            if ($run->script->fingerprint !== $run->fingerprint) {
                $run->update(['status' => 'superseded']);
                continue;
            }
            // Only the request that changes it from pending sends it (no double delivery).
            if (ScriptRun::query()->whereKey($run->id)->where('status', 'pending')->update(['status' => 'sent', 'sent_at' => now()]) === 1) {
                $payloads[] = $run->toSignedPayload();
            }
        }

        return response()->json(['runs' => $payloads]);
    });

    // The result of a run: exit codes and the output (shortened), for this device's runs only.
    Route::post('/device/scripts/runs/{run}', function (Request $request, int $run) {
        /** @var Device $device */
        $device = $request->user();
        $scriptRun = ScriptRun::query()->whereKey($run)->where('device_id', $device->id)->first();
        abort_if($scriptRun === null, 404);
        if ($scriptRun->status !== 'sent') {
            return response()->json(['error' => 'not_running'], 409);
        }

        $status = (string) $request->json('status');
        $exit = fn (string $key) => is_numeric($request->json($key)) ? max(-32768, min(32767, (int) $request->json($key))) : null;
        $error = $request->json('error');
        // The agent reports the fingerprint it verified and ran.
        if ($request->json('fingerprint') !== $scriptRun->fingerprint && $status !== 'rejected') {
            $status = 'error';
            $error = 'The device ran a different fingerprint';
        }

        $scriptRun->status = in_array($status, ScriptRun::RESULTS, true) ? $status : 'error';
        $scriptRun->detection_exit = $exit('detection_exit');
        $scriptRun->remediation_exit = $exit('remediation_exit');
        $scriptRun->post_detection_exit = $exit('post_detection_exit');
        $scriptRun->output = mb_strcut((string) $request->json('output'), 0, ScriptRun::MAX_OUTPUT) ?: null;
        $scriptRun->error = is_string($error) && $error !== '' ? mb_strcut($error, 0, 1000) : null;
        $scriptRun->finished_at = now();
        $scriptRun->save();

        return response()->json(['status' => $scriptRun->status]);
    });

    // Agents before 1.7.0 acknowledge a command before executing it so it is not delivered twice.
    Route::post('/device/commands/ack', function (Request $request) {
        /** @var Device $device */
        $device = $request->user();
        $command = $request->input('command');

        $device->commands = array_values(array_diff($device->commands ?? [], [$command]));
        $device->save();

        return response()->json(['commands' => $device->commands]);
    });
});
