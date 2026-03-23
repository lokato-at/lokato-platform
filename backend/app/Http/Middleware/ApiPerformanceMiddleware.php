<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiPerformanceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $response = $next($request);
        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

        $response->headers->set('X-Response-Time-ms', number_format($durationMs, 2, '.', ''));

        $slowRequestThresholdMs = (float) config('app.api_slow_request_ms', 400);

        if ($durationMs >= $slowRequestThresholdMs) {
            Log::channel('stack')->warning('Slow API request detected', [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => round($durationMs, 2),
                'memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ]);
        }

        return $response;
    }
}
