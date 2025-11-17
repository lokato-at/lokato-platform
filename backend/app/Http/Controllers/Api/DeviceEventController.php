<?php


namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceScanRequest;
use App\Models\Child;
use App\Models\ChildLocation;
use App\Models\Device;
use App\Models\MovementLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class DeviceEventController extends Controller
{
    /**
     * POST /api/v1/scan
     *
     * Payload:
     * - device_key
     * - tracker_uid
     * - event_time (optional)
     */
    public function store(DeviceScanRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // 1. Gerät finden
        $device = Device::where('device_key', $validated['device_key'])->first();

        if (!$device) {
            return response()->json([
                'error' => 'device_not_found',
                'message' => 'Das Gerät mit diesem Device-Key ist unbekannt.',
            ], 404);
        }

        // 2. Kind via tracker_uid finden
        $child = Child::where('tracker_uid', $validated['tracker_uid'])->first();

        if (!$child) {
            return response()->json([
                'error' => 'child_not_found',
                'message' => 'Kein Kind mit diesem Tracker gefunden.',
            ], 404);
        }

        // 3. Zeitpunkt bestimmen
        $occurredAt = isset($validated['event_time'])
            ? Carbon::parse($validated['event_time'])
            : now();

        // 4. bisherigen Raum aus child_locations lesen
        $currentLocation = ChildLocation::where('child_id', $child->id)->first();
        $fromRoomId = $currentLocation?->room_id;
        $toRoomId = $device->room_id;

        // 5. Eintrag in movement_log schreiben
        $movement = MovementLog::create([
            'child_id' => $child->id,
            'from_room_id' => $fromRoomId,
            'to_room_id' => $toRoomId,
            'device_id' => $device->id,
            'source' => 'device',
            'occurred_at' => $occurredAt,
        ]);

        // 6. child_locations aktualisieren – aber nur, wenn dieses Event
        //    nicht älter ist als der aktuell bekannte Standort.
        if ($currentLocation === null || $occurredAt->greaterThanOrEqualTo($currentLocation->updated_at)) {
            ChildLocation::updateOrCreate(
                ['child_id' => $child->id],
                [
                    'room_id' => $toRoomId,
                    'updated_at' => $occurredAt,
                ]
            );
        }

        // last_seen des Geräts aktualisieren (optional nicht mit event_time, sondern mit "jetzt")
        $device->last_seen = now();
        $device->save();

        return response()->json([
            'status' => 'ok',
            'movement' => [
                'id' => $movement->id,
                'child_id' => $movement->child_id,
                'from_room_id' => $movement->from_room_id,
                'to_room_id' => $movement->to_room_id,
                'occurred_at' => $movement->occurred_at->toIso8601String(),
            ],
        ]);
    }
}
