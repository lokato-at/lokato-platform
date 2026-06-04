<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Wakeup-Signal fuer SSE-Loops. MQTT-Subscriber und DeviceEventController rufen
 * bump() nach erfolgreichem Scan-Ingest auf; SseStreamController liest
 * lastChangeAt() in jedem Poll-Tick und ueberspringt die DB-Queries, solange
 * der Wert sich nicht geaendert hat.
 *
 * Cache::forever, weil der Wert ohnehin bei jedem Scan ueberschrieben wird und
 * eine TTL nur dafuer sorgen wuerde, dass das Gate nach langer Idle-Phase
 * sinnlos abfaellt.
 */
class SseChangeSignal
{
    private const CACHE_KEY = 'sse:last_change_at';

    public function bump(): void
    {
        Cache::forever(self::CACHE_KEY, microtime(true));
    }

    public function lastChangeAt(): float
    {
        return (float) Cache::get(self::CACHE_KEY, 0);
    }
}
