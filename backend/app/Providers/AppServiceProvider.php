<?php

namespace App\Providers;

use App\Support\AppLogger;
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
}
