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
use Illuminate\Support\Facades\DB;

class DeviceEventController extends Controller
{
    public function store(DeviceScanRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($validated) {

            // Gerät finden (per device_key, oder api_key wenn du das noch nutzt)
            $device = Device::where('device_key', $validated['device_key'])->firstOrFail();

            // Kind via tracker_uid
            $child  = Child::where('tracker_uid', $validated['tracker_uid'])->firstOrFail();

            // Zeitpunkt bestimmen
            $occurredAt = isset($validated['event_time'])
                ? Carbon::parse($validated['event_time'])
                : now();

            // aktuellen Standort sperren (Race-Conditions verhindern)
            $currentLocation = ChildLocation::where('child_id', $child->id)
                ->lockForUpdate()
                ->first();

            $fromRoomId = $currentLocation?->room_id;
            $toRoomId   = $device->room_id;

            // Bewegung loggen
            $movement = MovementLog::create([
                'child_id'     => $child->id,
                'from_room_id' => $fromRoomId,
                'to_room_id'   => $toRoomId,
                'device_id'    => $device->id,
                'source'       => 'device',
                'occurred_at'  => $occurredAt,
            ]);

            // aktuellen Standort nur aktualisieren, wenn Event nicht älter ist
            if ($currentLocation === null || $occurredAt->gte($currentLocation->updated_at)) {
                ChildLocation::updateOrCreate(
                    ['child_id' => $child->id],
                    [
                        'room_id'    => $toRoomId,
                        'updated_at' => $occurredAt,
                    ]
                );
            }

            // Gerät als "gesehen" markieren
            $device->last_seen = now();
            $device->save();

            return response()->json([
                'status'   => 'ok',
                'movement' => [
                    'id'           => $movement->id,
                    'child_id'     => $movement->child_id,
                    'from_room_id' => $movement->from_room_id,
                    'to_room_id'   => $movement->to_room_id,
                    'device_id'    => $movement->device_id,
                    'source'       => $movement->source,
                    'occurred_at'  => $movement->occurred_at->toIso8601String(),
                ],
            ]);
        });
    }
}
