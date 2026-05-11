<?php

namespace App\Services;

use App\Models\Child;
use App\Models\ChildLocation;
use App\Models\Device;
use App\Models\MovementLog;
use App\Support\AppLogger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
            $dbStart = microtime(true);

            if (AppLogger::shouldLogDiagnostics('db')) {
                AppLogger::event('db', 'scan_db_lookup_started', ['device_key' => $deviceKey], 'debug');
            }

            $device = Device::query()->select(['id', 'device_key', 'room_id'])->where('device_key', $deviceKey)->first();
            if (! $device) {
                AppLogger::event('scan', 'scan_device_not_found', ['device_key' => $deviceKey], 'warning');
                return null;
            }

            $child = Child::query()->select(['id', 'tracker_uid', 'is_active'])->where('tracker_uid', $trackerUid)->lockForUpdate()->first();
            if (! $child) {
                AppLogger::event('scan', 'scan_child_not_found', ['tracker_uid' => $trackerUid], 'warning');
                return null;
            }

            $occurredAt = $eventTimeIso ? Carbon::parse($eventTimeIso) : now();
            $currentLocation = ChildLocation::query()->select(['child_id', 'room_id', 'updated_at'])->where('child_id', $child->id)->lockForUpdate()->first();
            $fromRoomId = $currentLocation?->room_id;
            $toRoomId = $device->room_id;

            $movement = MovementLog::create([
                'child_id' => $child->id, 'from_room_id' => $fromRoomId, 'to_room_id' => $toRoomId, 'device_id' => $device->id, 'source' => $source, 'occurred_at' => $occurredAt,
            ]);

            if ($currentLocation === null) {
                ChildLocation::create(['child_id' => $child->id, 'room_id' => $toRoomId, 'updated_at' => $occurredAt]);
            } elseif ($occurredAt->gte($currentLocation->updated_at)) {
                $currentLocation->forceFill(['room_id' => $toRoomId, 'updated_at' => $occurredAt])->save();
            }

            $wasActive = (bool) $child->is_active;
            if (!$wasActive) {
                $child->is_active = true;
                $child->save();
            }

            Device::query()->whereKey($device->id)->update(['last_seen' => now()]);

            $dbDuration = (int) ((microtime(true) - $dbStart) * 1000);
            if (AppLogger::shouldLogDiagnostics('db')) {
                AppLogger::event('db', 'scan_db_lookup_finished', ['device_id' => $device->id, 'child_id' => $child->id, 'db_duration_ms' => $dbDuration], 'debug');
            }

            AppLogger::event('scan', 'scan_processed', [
                'movement_id' => $movement->id,
                'device_id' => $device->id,
                'child_id' => $child->id,
                'source' => $source,
                'is_active_before' => $wasActive,
                'is_active_after' => true,
                'db_duration_ms' => $dbDuration,
                'ip' => $requestIp,
            ], AppLogger::shouldLogDiagnostics('scan') ? 'info' : 'debug');

            return $movement;
        });
    }
}
