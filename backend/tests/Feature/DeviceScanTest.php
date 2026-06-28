<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildLocation;
use App\Models\Device;
use App\Models\MovementLog;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeviceScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_moves_child_and_updates_location_and_log(): void
    {
        $fromRoom = Room::create([
            'name'      => 'Gruppenraum 1',
            'area'      => 'EG',
            'capacity'  => 20,
            'tolerance' => 2,
            'is_active' => true,
        ]);

        $toRoom = Room::create([
            'name'      => 'Gruppenraum 2',
            'area'      => 'EG',
            'capacity'  => 20,
            'tolerance' => 2,
            'is_active' => true,
        ]);

        $device = Device::create([
            'name'       => 'Scanner Gruppenraum 2',
            'device_key' => 'raspberry_1',
            'room_id'    => $toRoom->id,
        ]);

        $child = Child::create([
            'name'        => 'Anna Muster',
            'photo_url'   => null,
            'tracker_uid' => 'TAG-0001',
            'is_active'   => true,
        ]);

        ChildLocation::create([
            'child_id'   => $child->id,
            'room_id'    => $fromRoom->id,
            'updated_at' => now()->subMinutes(5),
        ]);

        $eventTime = Carbon::now()->subMinute()->toIso8601String();

        $response = $this->postJson('/api/v1/scan', [
            'device_key'  => $device->device_key,
            'tracker_uid' => $child->tracker_uid,
            'event_time'  => $eventTime,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('movement.child_id', $child->id)
            ->assertJsonPath('movement.to_room_id', $toRoom->id);

        $this->assertDatabaseHas('movement_log', [
            'child_id'     => $child->id,
            'from_room_id' => $fromRoom->id,
            'to_room_id'   => $toRoom->id,
            'device_id'    => $device->id,
            'source'       => 'device',
        ]);

        $this->assertDatabaseHas('child_locations', [
            'child_id' => $child->id,
            'room_id'  => $toRoom->id,
        ]);

        $this->assertNotNull($device->fresh()->last_seen);
    }

    public function test_scan_returns_ok_with_null_movement_if_device_unknown(): void
    {
        $child = Child::create([
            'name'        => 'Ben Beispiel',
            'photo_url'   => null,
            'tracker_uid' => 'TAG-0002',
            'is_active'   => true,
        ]);

        $response = $this->postJson('/api/v1/scan', [
            'device_key'  => 'unknown_device',
            'tracker_uid' => $child->tracker_uid,
            'event_time'  => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('movement', null);

        $this->assertSame(0, MovementLog::count(), 'unknown device must not write a movement');
    }

    public function test_scan_returns_ok_with_null_movement_if_child_unknown(): void
    {
        $room = Room::create([
            'name'      => 'Gruppenraum',
            'area'      => 'EG',
            'capacity'  => 20,
            'tolerance' => 2,
            'is_active' => true,
        ]);

        $device = Device::create([
            'name'       => 'Scanner Gruppenraum',
            'device_key' => 'raspberry_2',
            'room_id'    => $room->id,
        ]);

        $response = $this->postJson('/api/v1/scan', [
            'device_key'  => $device->device_key,
            'tracker_uid' => 'UNBEKANNT',
            'event_time'  => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('movement', null);

        $this->assertSame(0, MovementLog::count(), 'unknown child must not write a movement');
    }

    public function test_same_room_scan_is_ignored(): void
    {
        $room = Room::create([
            'name'      => 'Obergeschoss',
            'area'      => 'OG',
            'capacity'  => 15,
            'tolerance' => 2,
            'is_active' => true,
        ]);
        $device = Device::create([
            'name'       => 'Scanner OG',
            'device_key' => 'raspberry_og',
            'room_id'    => $room->id,
        ]);
        $child = Child::create([
            'name'        => 'Kind im OG',
            'tracker_uid' => 'TAG-OG',
            'is_active'   => true,
        ]);
        ChildLocation::create([
            'child_id'   => $child->id,
            'room_id'    => $room->id,
            'updated_at' => now()->subMinute(),
        ]);

        $response = $this->postJson('/api/v1/scan', [
            'device_key'  => $device->device_key,
            'tracker_uid' => $child->tracker_uid,
            'event_time'  => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('movement', null);

        $this->assertSame(0, MovementLog::count(), 'same-room scan must not write a movement');
        $this->assertNotNull($device->fresh()->last_seen, 'last_seen must still be updated for telemetry');
    }
}
