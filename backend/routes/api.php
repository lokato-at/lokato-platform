<?php

use App\Http\Controllers\Api\Admin\ChildAdminController;
use App\Http\Controllers\Api\Admin\DeviceAdminController;
use App\Http\Controllers\Api\Admin\RoomAdminController;
use App\Http\Controllers\Api\Admin\AdminSummaryController;
use App\Http\Controllers\Api\Admin\TrackerSightingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChildrenController;
use App\Http\Controllers\Api\DeviceEventController;
use App\Http\Controllers\Api\MovementLogController;
use App\Http\Controllers\Api\RoomsController;
use App\Http\Controllers\Api\SseStreamController;
use App\Http\Controllers\Api\DiagnosticsController;
use Illuminate\Support\Facades\Route;

// Deine API v1
Route::prefix('v1')->group(function () {

    // Event / Tür-Scan — rate-limit defends against accidental publish loops
    // (Pro Source-IP; default 120 req/min, env-tunable via SCAN_RATE_LIMIT_PER_MINUTE)
    Route::post('/scan', [DeviceEventController::class, 'store'])
        ->middleware('throttle:' . config('app.scan_rate_limit', 120) . ',1');

    // Kinder (aktueller Standort) — Read-Endpoints sind public für Tablets
    // ohne Login. Schreibender Checkout braucht Bearer-Token.
    Route::get('/children', [ChildrenController::class, 'index']);
    Route::get('/children/{child}', [ChildrenController::class, 'show']);
    Route::post('/children/{child}/checkout', [ChildrenController::class, 'checkout'])
        ->middleware('auth:sanctum');

    // Historie – global oder pro Kind
    Route::get('/movement-log', [MovementLogController::class, 'index']);
    Route::get('/children/{child}/movement-log', [MovementLogController::class, 'byChild']);

    // Räume & Belegung
    Route::get('/rooms', [RoomsController::class, 'index']);
    Route::get('/rooms/{room}/occupancy', [RoomsController::class, 'occupancy']);

    // Auth — login is public (throttle against brute force);
    // logout + me require a valid bearer token.
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:10,1');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    // Admin endpoints require authentication.
    Route::middleware('auth:sanctum')->prefix('admin')->group(function () {

        Route::get('/summary', AdminSummaryController::class);

        // /api/v1/admin/children
        Route::apiResource('children', ChildAdminController::class);
        Route::post('children/{child}/photo', [ChildAdminController::class, 'uploadPhoto']);
        Route::delete('children/{child}/photo', [ChildAdminController::class, 'deletePhoto']);

        // /api/v1/admin/rooms
        Route::apiResource('rooms', RoomAdminController::class);

        // /api/v1/admin/devices
        Route::apiResource('devices', DeviceAdminController::class);

        // /api/v1/admin/tracker-sightings — Anlern-Modus: gescannte, noch nicht
        // zugewiesene Tracker-UIDs (Polling durch die Kinder-Admin-View).
        Route::get('tracker-sightings', [TrackerSightingController::class, 'index']);
        Route::delete('tracker-sightings/{trackerUid}', [TrackerSightingController::class, 'destroy']);
    });
});


// Einziger SSE-Endpoint. Query-Params steuern Modus:
//   GET /api/stream                       → Dashboard (alle Räume)
//   GET /api/stream?room=3&initial=1      → Raumtablet (auf Raum 3 gescopet,
//                                            mit initialem Occupancy-Snapshot)
Route::get('/stream', [SseStreamController::class, 'stream']);


Route::get('/health', [DiagnosticsController::class, 'health']);
Route::get('/readiness', [DiagnosticsController::class, 'readiness']);
if (config('app.diagnostics_enabled')) {
    Route::get('/diagnostics', [DiagnosticsController::class, 'readiness']);
}
