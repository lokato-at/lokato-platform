<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildLocation;
use App\Models\Device;
use App\Models\MovementLog;
use App\Models\Room;
use App\Services\ScanIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScanIngestServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixtures(): array
    {
        $room = Room::create([
            'name' => 'Garten', 'area' => 'Aussen',
            'capacity' => 20, 'tolerance' => 2, 'is_active' => true,
        ]);
        $device = Device::create([
            'name' => 'Scanner Garten',
            'device_key' => 'raspberry_test',
            'room_id' => $room->id,
        ]);
        $child = Child::create([
            'name' => 'Test-Kind', 'tracker_uid' => 'TEST-UID-001',
            'is_active' => false,
        ]);

        return ['room' => $room, 'device' => $device, 'child' => $child];
    }

    public function test_first_scan_creates_movement_and_child_location(): void
    {
        ['room' => $room, 'device' => $device, 'child' => $child] = $this->makeFixtures();
        $service = app(ScanIngestService::class);

        $movement = $service->ingestScan(
            deviceKey: $device->device_key,
            trackerUid: $child->tracker_uid,
            eventTimeIso: now()->toIso8601String(),
            source: 'mqtt_scanner',
        );

        $this->assertNotNull($movement, 'first scan should return a MovementLog instance');
        $this->assertSame($child->id, $movement->child_id);
        $this->assertNull($movement->from_room_id, 'first scan has no previous room');
        $this->assertSame($room->id, $movement->to_room_id);

        $this->assertDatabaseHas('child_locations', [
            'child_id' => $child->id,
            'room_id' => $room->id,
        ]);

        $this->assertTrue($child->fresh()->is_active, 'first scan must flip is_active to true');
    }

    public function test_older_scan_does_not_move_child_backwards(): void
    {
        ['room' => $gartenRoom, 'child' => $child] = $this->makeFixtures();
        $service = app(ScanIngestService::class);

        // Zweiter Raum/Scanner, sonst greift der Same-Room-Skip statt der
        // Idempotenz-Logik, die hier getestet werden soll.
        $obergeschoss = Room::create([
            'name' => 'Obergeschoss', 'area' => 'OG',
            'capacity' => 15, 'tolerance' => 2, 'is_active' => true,
        ]);
        $deviceOg = Device::create([
            'name' => 'Scanner OG',
            'device_key' => 'raspberry_og',
            'room_id' => $obergeschoss->id,
        ]);

        // Aktuelle Location: Garten, jetzt gesetzt
        $currentTime = Carbon::now();
        ChildLocation::create([
            'child_id' => $child->id,
            'room_id' => $gartenRoom->id,
            'updated_at' => $currentTime,
        ]);

        // Älterer Scan ins Obergeschoss kommt verspätet rein — soll NICHT die
        // (neuere) Garten-Location überschreiben.
        $olderEventTime = $currentTime->copy()->subMinutes(10)->toIso8601String();
        $movement = $service->ingestScan(
            deviceKey: $deviceOg->device_key,
            trackerUid: $child->tracker_uid,
            eventTimeIso: $olderEventTime,
            source: 'mqtt_scanner',
        );

        // Movement wird trotzdem geloggt (Append-Only-Historie), aber Location bleibt
        $this->assertNotNull($movement);
        $location = ChildLocation::where('child_id', $child->id)->first();
        $this->assertSame($gartenRoom->id, $location->room_id, 'location must not flip to the older-scan room');
        $this->assertTrue(
            $currentTime->equalTo($location->updated_at) || $currentTime->greaterThan($location->updated_at),
            'older scan must not overwrite a newer current location'
        );
    }

    public function test_same_room_scan_returns_null_and_writes_no_movement(): void
    {
        ['room' => $room, 'device' => $device, 'child' => $child] = $this->makeFixtures();
        $service = app(ScanIngestService::class);

        // Kind ist bereits im Garten
        ChildLocation::create([
            'child_id' => $child->id,
            'room_id' => $room->id,
            'updated_at' => Carbon::now()->subMinute(),
        ]);

        $result = $service->ingestScan(
            deviceKey: $device->device_key,
            trackerUid: $child->tracker_uid,
            eventTimeIso: now()->toIso8601String(),
            source: 'mqtt_scanner',
        );

        $this->assertNull($result, 'same-room scan must return null');
        $this->assertSame(0, MovementLog::count(), 'no MovementLog row must be created');

        // Location bleibt unverändert
        $this->assertDatabaseHas('child_locations', [
            'child_id' => $child->id,
            'room_id' => $room->id,
        ]);

        // Device.last_seen wird trotzdem aktualisiert (Telemetrie)
        $this->assertNotNull($device->fresh()->last_seen);
    }

    public function test_unknown_device_returns_null(): void
    {
        $service = app(ScanIngestService::class);
        $child = Child::create(['name' => 'X', 'tracker_uid' => 'TAG-X', 'is_active' => false]);

        $result = $service->ingestScan('does-not-exist', $child->tracker_uid);

        $this->assertNull($result);
        $this->assertSame(0, MovementLog::count());
    }

    public function test_unknown_child_returns_null(): void
    {
        ['device' => $device] = $this->makeFixtures();
        $service = app(ScanIngestService::class);

        $result = $service->ingestScan($device->device_key, 'unknown-tracker');

        $this->assertNull($result);
        $this->assertSame(0, MovementLog::count());
    }
}
