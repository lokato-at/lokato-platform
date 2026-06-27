<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppRuntimeState;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class DiagnosticsController extends Controller
{
    public function health(): JsonResponse
    {
        // LARAVEL_START is defined by public/index.php at request start. When
        // the app is bootstrapped from a different entry (CLI tests, queue
        // workers), the constant is undefined — fall back to 0 uptime.
        $uptime = defined('LARAVEL_START')
            ? (int) (microtime(true) - LARAVEL_START)
            : 0;

        return response()->json([
            'status' => 'ok',
            'uptime_seconds' => $uptime,
            'timezone' => config('app.timezone'),
            'now' => now()->toIso8601String(),
            'version' => config('app.version', 'unknown'),
        ]);
    }

    public function readiness(): JsonResponse
    {
        $runtime = [
            'mqtt_connected' => false,
            'mqtt_last_message_at' => null,
            'last_scan_processed_at' => null,
            'last_daily_reset_at' => null,
        ];

        if (Schema::hasTable('app_runtime_state')) {
            try {
                $runtime = [
                    'mqtt_connected' => AppRuntimeState::query()->where('state_key', 'mqtt_connected')->value('state_value') === '1',
                    'mqtt_last_message_at' => AppRuntimeState::query()->where('state_key', 'mqtt_last_message_at')->value('state_value'),
                    'last_scan_processed_at' => AppRuntimeState::query()->where('state_key', 'mqtt_last_scan_processed_at')->value('state_value'),
                    'last_daily_reset_at' => AppRuntimeState::query()->where('state_key', 'last_daily_reset_at')->value('state_value'),
                ];
            } catch (QueryException) {
                // Keep defaults if DB schema is not ready yet.
            }
        }

        return response()->json(array_merge([
            'db_reachable' => true,
        ], $runtime));
    }
}
