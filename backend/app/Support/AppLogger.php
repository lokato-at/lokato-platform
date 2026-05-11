<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

class AppLogger
{
    public static function enabled(): bool
    {
        return filter_var(env('LOG_ENABLED', true), FILTER_VALIDATE_BOOL);
    }

    public static function format(): string
    {
        $format = strtolower((string) env('LOG_FORMAT', 'pretty'));
        return in_array($format, ['pretty', 'json'], true) ? $format : 'pretty';
    }

    public static function shouldLogDiagnostics(string $type): bool
    {
        return match ($type) {
            'mqtt' => filter_var(env('MQTT_DIAGNOSTIC_LOGS', false), FILTER_VALIDATE_BOOL),
            'scan' => filter_var(env('SCAN_DIAGNOSTIC_LOGS', true), FILTER_VALIDATE_BOOL),
            'cron' => filter_var(env('CRON_LOGS', true), FILTER_VALIDATE_BOOL),
            'db' => filter_var(env('DB_DIAGNOSTIC_LOGS', false), FILTER_VALIDATE_BOOL),
            default => false,
        };
    }

    public static function event(string $component, string $event, array $context = [], string $level = 'info', bool $force = false): void
    {
        if (!self::enabled() && !in_array($level, ['critical', 'error'], true) && !$force) {
            return;
        }

        $payload = array_merge([
            'component' => $component,
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
        ], self::sanitize($context));

        if (self::format() === 'json') {
            Log::log($level, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }

        Log::log($level, $event, $payload);
    }

    public static function exception(string $component, string $event, Throwable $e, array $context = []): void
    {
        self::event($component, $event, array_merge($context, [
            'error_name' => get_class($e),
            'error_message' => $e->getMessage(),
            'stacktrace' => $e->getTraceAsString(),
        ]), 'error', true);
    }

    private static function sanitize(array $context): array
    {
        unset($context['password'], $context['secret'], $context['token']);
        return $context;
    }
}
