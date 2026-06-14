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

        // env() returns null after `php artisan config:cache` because Laravel
        // only reads from bootstrap/cache/config.php at that point. Diagnostic
        // values must come from config() / hard-coded constants, not env().
        AppLogger::event('app', 'startup', [
            'timezone' => config('app.timezone'),
            'php' => PHP_VERSION,
            'mqtt_host' => config('mqtt-client.connections.default.host'),
            'db_host' => config('database.connections.mysql.host'),
        ], 'info', true);
    }

    /**
     * Defense-in-depth check that all required infrastructure env vars made
     * it into config. Reads via config() so the check works both before and
     * after `php artisan config:cache`.
     */
    private function validateEnv(): void
    {
        $required = [
            'DB_HOST'     => config('database.connections.mysql.host'),
            'DB_PORT'     => config('database.connections.mysql.port'),
            'DB_DATABASE' => config('database.connections.mysql.database'),
            'DB_USERNAME' => config('database.connections.mysql.username'),
            'MQTT_HOST'   => config('mqtt-client.connections.default.host'),
            'MQTT_PORT'   => config('mqtt-client.connections.default.port'),
        ];

        $missing = [];
        foreach ($required as $envKey => $value) {
            if ($value === null || $value === '') {
                $missing[] = $envKey;
            }
        }

        if ($missing !== []) {
            AppLogger::event('config', 'env_validation_failed', ['missing' => $missing], 'critical', true);
            throw new \RuntimeException('Missing required env: '.implode(', ', $missing));
        }
    }
}
