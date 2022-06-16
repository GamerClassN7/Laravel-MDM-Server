<?php

use App\Models\Device;
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

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::post('/device', function (Request $request) {
  $data = $request->json()->all();
  Log::error($data);
  $device = Device::Where("name", $data['machine']['hostname'])->first();
  if ($device === null) {
        $device = new Device();
        $device->name = $data['machine']['hostname'];
        $device->os = $data['machine']['os'];
        $device->drives = $data['machine']['drives'];
        $device->data = json_encode($data);

        $device->save();
        return "registered";
    }else {
        $device->drives = $data['machine']['drives'];
        $device->data = json_encode($data);
    }
    $commands = $device->commands;
    $device->commands = [];
    $device->save();
    
    return response()->json([
        'commands' => $commands,
    ]);
});
