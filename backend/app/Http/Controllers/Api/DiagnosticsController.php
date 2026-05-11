<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppRuntimeState;
use Illuminate\Http\JsonResponse;

class DiagnosticsController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'uptime_seconds' => (int) (microtime(true) - LARAVEL_START),
            'timezone' => config('app.timezone'),
            'now' => now()->toIso8601String(),
            'version' => config('app.version', 'unknown'),
        ]);
    }

    public function readiness(): JsonResponse
    {
        return response()->json([
            'db_reachable' => true,
            'mqtt_connected' => AppRuntimeState::query()->where('state_key', 'mqtt_connected')->value('state_value') === '1',
            'mqtt_last_message_at' => AppRuntimeState::query()->where('state_key', 'mqtt_last_message_at')->value('state_value'),
            'last_scan_processed_at' => AppRuntimeState::query()->where('state_key', 'mqtt_last_scan_processed_at')->value('state_value'),
            'last_daily_reset_at' => AppRuntimeState::query()->where('state_key', 'last_daily_reset_at')->value('state_value'),
        ]);
    }
}
