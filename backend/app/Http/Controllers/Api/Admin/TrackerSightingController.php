<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TrackerSighting;
use Illuminate\Http\JsonResponse;

class TrackerSightingController extends Controller
{
    /**
     * GET /api/v1/admin/tracker-sightings
     *
     * Gescannte, aber (noch) keinem Kind zugewiesene Tracker-UIDs — neueste
     * zuerst. Defensiv gefiltert: UIDs, die inzwischen einem Kind gehoeren,
     * tauchen nicht auf (falls forget() mal nicht lief, z.B. Direkt-DB-Import).
     */
    public function index(): JsonResponse
    {
        $sightings = TrackerSighting::query()
            ->with(['device:id,name', 'room:id,name'])
            ->whereNotIn('tracker_uid', function ($query) {
                $query->select('tracker_uid')->from('children')->whereNotNull('tracker_uid');
            })
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get()
            ->map(fn (TrackerSighting $sighting) => [
                'tracker_uid'  => $sighting->tracker_uid,
                'device_id'    => $sighting->device_id,
                'device_name'  => $sighting->device?->name,
                'room_id'      => $sighting->room_id,
                'room_name'    => $sighting->room?->name,
                'last_seen_at' => $sighting->last_seen_at?->toIso8601String(),
            ])
            ->values();

        return response()->json($sightings);
    }

    /**
     * DELETE /api/v1/admin/tracker-sightings/{trackerUid}
     * Sichtung manuell verwerfen (z.B. Fehlscan / fremder Tracker).
     */
    public function destroy(string $trackerUid): JsonResponse
    {
        TrackerSighting::query()->whereKey($trackerUid)->delete();

        return response()->json(['deleted' => true]);
    }
}
