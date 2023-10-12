<?php

use App\Models\Device;
use App\Models\Enrolment;
use Carbon\CarbonImmutable;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->post('/device', function (Request $request) {
    /** @var Devices $device */
    $device = auth()->user();

    $data = $request->json()->all();
    Log::error($data);

    if ($device === null) {
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
