<?php

use App\Http\Controllers\Api\Admin\ChildAdminController;
use App\Http\Controllers\Api\Admin\DeviceAdminController;
use App\Http\Controllers\Api\Admin\RoomAdminController;
use App\Http\Controllers\Api\Admin\AdminSummaryController;
use App\Http\Controllers\Api\ChildrenController;
use App\Http\Controllers\Api\DeviceEventController;
use App\Http\Controllers\Api\MovementLogController;
use App\Http\Controllers\Api\RoomsController;
use App\Http\Controllers\Api\SseStreamController;
use App\Http\Controllers\Api\DiagnosticsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Deine API v1
Route::prefix('v1')->group(function () {

    // Event / Tür-Scan
    Route::post('/scan', [DeviceEventController::class, 'store']);

    // Kinder (aktueller Standort)
    Route::get('/children', [ChildrenController::class, 'index']);
    Route::get('/children/{child}', [ChildrenController::class, 'show']);
    Route::post('/children/{child}/checkout', [ChildrenController::class, 'checkout']);

    // Historie – global oder pro Kind
    Route::get('/movement-log', [MovementLogController::class, 'index']);
    Route::get('/children/{child}/movement-log', [MovementLogController::class, 'byChild']);

    // Räume & Belegung
    Route::get('/rooms', [RoomsController::class, 'index']);
    Route::get('/rooms/{room}/occupancy', [RoomsController::class, 'occupancy']);

    Route::prefix('admin')->group(function () {

        Route::get('/summary', AdminSummaryController::class);

        // /api/v1/admin/children
        Route::apiResource('children', ChildAdminController::class);

        // /api/v1/admin/rooms
        Route::apiResource('rooms', RoomAdminController::class);

        // /api/v1/admin/devices
        Route::apiResource('devices', DeviceAdminController::class);
    });
});


Route::prefix('stream')->group(function () {
    // Globaler Dashboard-Stream (alle Räume, Bewegungen, Alarme)
    Route::get('/dashboard', [SseStreamController::class, 'dashboard']);

    // Raum-spezifischer Stream (nur ein Raum, nur Occupancy + Alerts)
    Route::get('/room/{room}', [SseStreamController::class, 'room']);
});


Route::get('/health', [DiagnosticsController::class, 'health']);
Route::get('/readiness', [DiagnosticsController::class, 'readiness']);
if ((bool) env('DIAGNOSTICS_ENABLED', true)) {
    Route::get('/diagnostics', [DiagnosticsController::class, 'readiness']);
}
