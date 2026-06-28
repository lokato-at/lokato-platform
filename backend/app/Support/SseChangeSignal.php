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
    // Separater Key für Children-Metadaten-Aenderungen (is_active toggle via
    // Admin, neue Kinder, Delete). Anders als bei Scans gibt es keinen
    // MovementLog-Eintrag, also kann der Stream den Trigger nicht ueber
    // movement_log finden — er muss explizit gepollt werden.
    private const CHILDREN_CACHE_KEY = 'sse:last_children_change_at';

    public function bump(): void
    {
        Cache::forever(self::CACHE_KEY, microtime(true));
    }

    public function lastChangeAt(): float
    {
        return (float) Cache::get(self::CACHE_KEY, 0);
    }

    /**
     * Wird vom ChildAdminController nach Create/Update/Delete gerufen.
     * Triggert im SseStreamController eine Refresh aller Raum-Occupancy-
     * Snapshots beim naechsten Tick.
     */
    public function bumpChildren(): void
    {
        $now = microtime(true);
        Cache::forever(self::CHILDREN_CACHE_KEY, $now);
        // bump() rufen wir gleich mit auf, damit der SSE-Loop ueberhaupt aufwacht.
        Cache::forever(self::CACHE_KEY, $now);
    }

    public function lastChildrenChangeAt(): float
    {
        return (float) Cache::get(self::CHILDREN_CACHE_KEY, 0);
    }
}
