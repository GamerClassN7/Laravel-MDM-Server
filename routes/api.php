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
    
    if ($device === null){
        return;
    }
    
    $device->drives = $data['machine']['drives'];
    $device->name = $data['machine']['hostname'];
    $device->os = $data['machine']['os'];
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
    $invitation = Enrolment::where('code',$inviteCode)->where('expire_at', '>', CarbonImmutable::now())->first();
    
    if(null === $invitation){
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
