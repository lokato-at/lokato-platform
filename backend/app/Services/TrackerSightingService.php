<?php

namespace App\Services;

use App\Models\Device;
use App\Models\TrackerSighting;

/**
 * Haelt fest, welche Tracker-UIDs gescannt wurden, ohne einem Kind zu gehoeren.
 * Quelle fuer den Admin-Anlern-Modus.
 *
 * record() wird vom Ingest genau am scan_child_not_found-Zweig gerufen — dem
 * einzigen eindeutigen Choke-Point, der MQTT und REST in einem abdeckt.
 * forget() raeumt die Sichtung wieder weg, sobald der Tracker einem Kind
 * zugewiesen wurde.
 */
class TrackerSightingService
{
    public function record(string $trackerUid, ?Device $device = null): void
    {
        $trackerUid = trim($trackerUid);
        if ($trackerUid === '') {
            return;
        }

        // Upsert pro UID -> Tabelle bleibt klein, last_seen_at wandert nach vorne.
        TrackerSighting::query()->updateOrCreate(
            ['tracker_uid' => $trackerUid],
            [
                'device_id'    => $device?->id,
                'room_id'      => $device?->room_id,
                'last_seen_at' => now(),
            ],
        );
    }

    public function forget(?string $trackerUid): void
    {
        $trackerUid = trim((string) $trackerUid);
        if ($trackerUid === '') {
            return;
        }

        TrackerSighting::query()->whereKey($trackerUid)->delete();
    }
}
