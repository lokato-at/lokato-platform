<?php

namespace App\Services;

use App\Models\Child;
use App\Models\ChildLocation;
use App\Models\Device;
use App\Models\MovementLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScanIngestService
{
    public function ingestScan(string $deviceKey, string $trackerUid, ?string $eventTimeIso = null, string $source = 'mqtt_scanner'): ?MovementLog
    {
        return DB::transaction(function () use ($deviceKey, $trackerUid, $eventTimeIso, $source) {

            $device = Device::where('device_key', $deviceKey)->first();

            if (! $device) {
                Log::channel('scan')->warning('Unknown device_key on mqtt scan', [
                    'device_key'  => $deviceKey,
                    'tracker_uid' => $trackerUid,
                    'time'        => now()->toIso8601String(),
                ]);

                return null;
            }

            $child = Child::where('tracker_uid', $trackerUid)->first();

            if (! $child) {
                Log::channel('scan')->warning('Unknown tracker_uid on mqtt scan', [
                    'device_key'  => $device->device_key,
                    'device_id'   => $device->id,
                    'tracker_uid' => $trackerUid,
                    'time'        => now()->toIso8601String(),
                ]);

                return null;
            }

            $occurredAt = $eventTimeIso
                ? Carbon::parse($eventTimeIso)
                : now();

            $currentLocation = ChildLocation::where('child_id', $child->id)
                ->lockForUpdate()
                ->first();

            $fromRoomId = $currentLocation?->room_id;
            $toRoomId   = $device->room_id;

            $movement = MovementLog::create([
                'child_id'     => $child->id,
                'from_room_id' => $fromRoomId,
                'to_room_id'   => $toRoomId,
                'device_id'    => $device->id,
                'source'       => $source,
                'occurred_at'  => $occurredAt,
            ]);

            if ($currentLocation === null || $occurredAt->gte($currentLocation->updated_at)) {
                ChildLocation::updateOrCreate(
                    ['child_id' => $child->id],
                    [
                        'room_id'    => $toRoomId,
                        'updated_at' => $occurredAt,
                    ]
                );
            }

            $device->last_seen = now();
            $device->save();

            Log::channel('scan')->info('MQTT scan processed', [
                'movement_id'  => $movement->id,
                'device_key'   => $device->device_key,
                'device_id'    => $device->id,
                'child_id'     => $child->id,
                'tracker_uid'  => $child->tracker_uid,
                'from_room_id' => $fromRoomId,
                'to_room_id'   => $toRoomId,
                'occurred_at'  => $occurredAt->toIso8601String(),
            ]);

            return $movement;
        });
    }
}
