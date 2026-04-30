<?php

namespace App\Providers;

use App\Models\AppRuntimeState;
use App\Support\AppLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->validateEnv();
        $this->runDailyResetRecoveryIfNeeded();

        AppLogger::event('app', 'startup', [
            'timezone' => config('app.timezone'),
            'node' => PHP_VERSION,
            'log_enabled' => env('LOG_ENABLED', true),
            'log_level' => env('LOG_LEVEL', 'info'),
            'mqtt_host' => env('MQTT_HOST'),
            'db_host' => env('DB_HOST'),
        ], 'info', true);
    }

    private function validateEnv(): void
    {
        $required = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'MQTT_HOST', 'MQTT_PORT'];
        $missing = [];
        foreach ($required as $key) {
            if (env($key) === null || env($key) === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            AppLogger::event('config', 'env_validation_failed', ['missing' => $missing], 'critical', true);
            throw new \RuntimeException('Missing required env: '.implode(', ', $missing));
        }
    }

    private function runDailyResetRecoveryIfNeeded(): void
    {
        if (!Schema::hasTable('app_runtime_state')) {
            AppLogger::event('cron', 'daily_reset_recovery_skipped', [
                'reason' => 'app_runtime_state_table_missing',
            ], 'warning', true);
            return;
        }

        try {
            $today = now(config('app.timezone'))->toDateString();
            $lastResetDate = AppRuntimeState::query()->where('state_key', 'last_daily_reset_date')->value('state_value');

            if ($lastResetDate !== $today) {
                AppLogger::event('cron', 'daily_reset_recovery', [
                    'daily_reset_missed' => true,
                    'reset_date' => $today,
                ], 'warning', true);
                Artisan::call('children:daily-active-reset', ['--recovery' => true]);
            }
        } catch (QueryException $e) {
            AppLogger::event('cron', 'daily_reset_recovery_skipped', [
                'reason' => 'runtime_state_query_failed',
                'error_name' => get_class($e),
                'error_message' => $e->getMessage(),
            ], 'error', true);
        }
    }
}
