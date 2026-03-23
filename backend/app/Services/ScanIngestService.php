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
    public function ingestScan(
        string $deviceKey,
        string $trackerUid,
        ?string $eventTimeIso = null,
        string $source = 'mqtt_scanner',
        ?string $requestIp = null,
    ): ?MovementLog {
        return DB::transaction(function () use ($deviceKey, $trackerUid, $eventTimeIso, $source, $requestIp) {
            $device = Device::query()
                ->select(['id', 'device_key', 'room_id'])
                ->where('device_key', $deviceKey)
                ->first();

            if (! $device) {
                Log::channel('scan')->warning('Unknown device_key on scan', [
                    'device_key' => $deviceKey,
                    'tracker_uid' => $trackerUid,
                    'ip' => $requestIp,
                    'time' => now()->toIso8601String(),
                ]);

                return null;
            }

            $child = Child::query()
                ->select(['id', 'tracker_uid'])
                ->where('tracker_uid', $trackerUid)
                ->first();

            if (! $child) {
                Log::channel('scan')->warning('Unknown tracker_uid on scan', [
                    'device_key' => $device->device_key,
                    'device_id' => $device->id,
                    'tracker_uid' => $trackerUid,
                    'ip' => $requestIp,
                    'time' => now()->toIso8601String(),
                ]);

                return null;
            }

            $occurredAt = $eventTimeIso
                ? Carbon::parse($eventTimeIso)
                : now();

            $currentLocation = ChildLocation::query()
                ->select(['child_id', 'room_id', 'updated_at'])
                ->where('child_id', $child->id)
                ->lockForUpdate()
                ->first();

            $fromRoomId = $currentLocation?->room_id;
            $toRoomId = $device->room_id;

            $movement = MovementLog::create([
                'child_id' => $child->id,
                'from_room_id' => $fromRoomId,
                'to_room_id' => $toRoomId,
                'device_id' => $device->id,
                'source' => $source,
                'occurred_at' => $occurredAt,
            ]);

            if ($currentLocation === null) {
                ChildLocation::create([
                    'child_id' => $child->id,
                    'room_id' => $toRoomId,
                    'updated_at' => $occurredAt,
                ]);
            } elseif ($occurredAt->gte($currentLocation->updated_at)) {
                $currentLocation->forceFill([
                    'room_id' => $toRoomId,
                    'updated_at' => $occurredAt,
                ])->save();
            }

            Device::query()
                ->whereKey($device->id)
                ->update(['last_seen' => now()]);

            Log::channel('scan')->info('Scan processed', [
                'movement_id' => $movement->id,
                'device_key' => $device->device_key,
                'device_id' => $device->id,
                'child_id' => $child->id,
                'tracker_uid' => $child->tracker_uid,
                'from_room_id' => $fromRoomId,
                'to_room_id' => $toRoomId,
                'occurred_at' => $occurredAt->toIso8601String(),
                'source' => $source,
                'ip' => $requestIp,
            ]);

            return $movement;
        });
    }
}
