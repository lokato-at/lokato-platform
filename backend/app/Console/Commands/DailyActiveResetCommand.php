<?php

namespace App\Console\Commands;

use App\Models\AppRuntimeState;
use App\Models\Child;
use App\Models\ChildLocation;
use App\Support\AppLogger;
use App\Support\SseChangeSignal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DailyActiveResetCommand extends Command
{
    protected $signature = 'children:daily-active-reset';
    protected $description = 'Setzt alle children.is_active auf 0, leert child_locations und weckt SSE-Clients auf.';

    public function handle(SseChangeSignal $sseChangeSignal): int
    {
        $start = microtime(true);
        $resetDate = now(config('app.timezone'))->toDateString();

        try {
            AppLogger::event('cron', 'daily_reset_started', ['reset_date' => $resetDate], AppLogger::shouldLogDiagnostics('cron') ? 'info' : 'debug');

            // children.is_active auf false setzen — naechster Scan setzt das wieder true.
            $affected = Child::query()->where('is_active', true)->update(['is_active' => false]);

            // child_locations komplett leeren — morgens sind alle Raeume leer.
            // Historie bleibt im movement_log (append-only) erhalten.
            $locationsCleared = ChildLocation::query()->delete();

            if (Schema::hasTable('app_runtime_state')) {
                AppRuntimeState::query()->updateOrCreate(
                    ['state_key' => 'last_daily_reset_date'],
                    ['state_value' => $resetDate]
                );

                AppRuntimeState::query()->updateOrCreate(
                    ['state_key' => 'last_daily_reset_at'],
                    ['state_value' => now()->toIso8601String()]
                );
            }

            // SSE-Clients aufwecken: alle offenen Dashboards/Tablets sollen
            // ihren naechsten Poll-Tick die jetzt leeren Raeume sehen.
            $sseChangeSignal->bump();

            AppLogger::event('cron', 'daily_reset_finished', [
                'reset_date' => $resetDate,
                'affected_children_count' => $affected,
                'locations_cleared' => $locationsCleared,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ], 'info');

            return self::SUCCESS;
        } catch (Throwable $e) {
            AppLogger::exception('cron', 'daily_reset_failed', $e, [
                'reset_date' => $resetDate,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);

            $this->error('Daily active reset failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
