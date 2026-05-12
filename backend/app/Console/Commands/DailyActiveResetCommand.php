<?php

namespace App\Console\Commands;

use App\Models\AppRuntimeState;
use App\Models\Child;
use App\Support\AppLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DailyActiveResetCommand extends Command
{
    protected $signature = 'children:daily-active-reset';
    protected $description = 'Setzt alle children.is_active auf 0 und speichert den täglichen Reset-Status.';

    public function handle(): int
    {
        $start = microtime(true);
        $resetDate = now(config('app.timezone'))->toDateString();

        try {
            AppLogger::event('cron', 'daily_reset_started', ['reset_date' => $resetDate], AppLogger::shouldLogDiagnostics('cron') ? 'info' : 'debug');

            $affected = Child::query()->where('is_active', true)->update(['is_active' => false]);

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

            AppLogger::event('cron', 'daily_reset_finished', [
                'reset_date' => $resetDate,
                'affected_children_count' => $affected,
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
