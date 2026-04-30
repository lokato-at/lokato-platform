<?php

namespace App\Console\Commands;

use App\Models\AppRuntimeState;
use App\Models\Child;
use App\Support\AppLogger;
use Illuminate\Console\Command;

class DailyActiveResetCommand extends Command
{
    protected $signature = 'children:daily-active-reset {--recovery}';
    protected $description = 'Setzt alle children.is_active auf 0 und speichert den täglichen Reset-Status.';

    public function handle(): int
    {
        $start = microtime(true);
        $resetDate = now(config('app.timezone'))->toDateString();

        AppLogger::event('cron', 'daily_reset_started', ['reset_date' => $resetDate], AppLogger::shouldLogDiagnostics('cron') ? 'info' : 'debug');

        $affected = Child::query()->where('is_active', true)->update(['is_active' => false]);

        AppRuntimeState::query()->updateOrCreate(
            ['state_key' => 'last_daily_reset_date'],
            ['state_value' => $resetDate]
        );

        AppRuntimeState::query()->updateOrCreate(
            ['state_key' => 'last_daily_reset_at'],
            ['state_value' => now()->toIso8601String()]
        );

        AppLogger::event('cron', 'daily_reset_finished', [
            'reset_date' => $resetDate,
            'affected_children_count' => $affected,
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            'recovery_mode' => (bool) $this->option('recovery'),
        ], 'info');

        return self::SUCCESS;
    }
}
