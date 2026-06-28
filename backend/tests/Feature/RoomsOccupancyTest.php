<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildLocation;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomsOccupancyTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_rooms_index_returns_current_occupancy_counts()
    {
        $room1 = Room::create([
            'name'      => 'Bastelraum',
            'area'      => 'UG',
            'capacity'  => 15,
            'tolerance' => 2,
            'is_active' => true,
        ]);

        $room2 = Room::create([
            'name'      => 'Turnsaal',
            'area'      => 'EG',
            'capacity'  => 20,
            'tolerance' => 3,
            'is_active' => true,
        ]);

        $child1 = Child::create([
            'name'        => 'Kind 1',
            'photo_url'   => null,
            'tracker_uid' => 'TAG-1',
            'is_active'   => true,
        ]);

        $child2 = Child::create([
            'name'        => 'Kind 2',
            'photo_url'   => null,
            'tracker_uid' => 'TAG-2',
            'is_active'   => true,
        ]);

        $child3 = Child::create([
            'name'        => 'Kind 3',
            'photo_url'   => null,
            'tracker_uid' => 'TAG-3',
            'is_active'   => true,
        ]);

        ChildLocation::create(['child_id' => $child1->id, 'room_id' => $room1->id]);
        ChildLocation::create(['child_id' => $child2->id, 'room_id' => $room1->id]);
        ChildLocation::create(['child_id' => $child3->id, 'room_id' => $room2->id]);

        $response = $this->getJson('/api/v1/rooms');

        $response->assertStatus(200);

        $data = $response->json();

        $bastelraum = collect($data)->firstWhere('id', $room1->id);
        $turnsaal   = collect($data)->firstWhere('id', $room2->id);

        $this->assertEquals(2, $bastelraum['current_count']);
        $this->assertEquals(1, $turnsaal['current_count']);
    }

    /** @test */
    public function test_room_occupancy_endpoint_returns_children_in_room()
    {
        $room = Room::create([
            'name'      => 'Leseraum',
            'area'      => 'OG',
            'capacity'  => 10,
            'tolerance' => 2,
            'is_active' => true,
        ]);

        $child1 = Child::create([
            'name'        => 'Lisa',
            'photo_url'   => null,
            'tracker_uid' => 'TAG-10',
            'is_active'   => true,
        ]);

        $child2 = Child::create([
            'name'        => 'Tom',
            'photo_url'   => null,
            'tracker_uid' => 'TAG-11',
            'is_active'   => true,
        ]);

        ChildLocation::create(['child_id' => $child1->id, 'room_id' => $room->id]);
        ChildLocation::create(['child_id' => $child2->id, 'room_id' => $room->id]);

        $response = $this->getJson("/api/v1/rooms/{$room->id}/occupancy");

        $response->assertStatus(200)
            ->assertJsonPath('room.id', $room->id)
            ->assertJsonPath('current_count', 2);

        $children = $response->json('children');
        $this->assertCount(2, $children);
    }

    /** @test */
    public function test_rooms_index_returns_within_tolerance_status_when_over_capacity_but_within_buffer()
    {
        // currentCount > capacity AND currentCount <= capacity + tolerance
        // => within_tolerance=true, over_capacity=false (Warnung)
        $room = Room::create([
            'name' => 'Mini-Raum', 'area' => 'EG',
            'capacity' => 2, 'tolerance' => 2, 'is_active' => true,
        ]);

        foreach (range(1, 3) as $i) {
            $child = Child::create([
                'name' => "Kind {$i}",
                'tracker_uid' => "WARN-TAG-{$i}",
                'is_active' => true,
            ]);
            ChildLocation::create(['child_id' => $child->id, 'room_id' => $room->id]);
        }

        $response = $this->getJson('/api/v1/rooms');

        $response->assertStatus(200);
        $entry = collect($response->json())->firstWhere('id', $room->id);

        $this->assertSame(3, $entry['current_count']);
        $this->assertTrue($entry['status']['within_tolerance']);
        $this->assertFalse($entry['status']['over_capacity']);
    }

    /** @test */
    public function test_rooms_index_returns_over_capacity_status_when_tolerance_exhausted()
    {
        // currentCount > capacity + tolerance => over_capacity=true (Ueberbelegt)
        $room = Room::create([
            'name' => 'Mini-Raum', 'area' => 'EG',
            'capacity' => 2, 'tolerance' => 2, 'is_active' => true,
        ]);

        foreach (range(1, 5) as $i) {
            $child = Child::create([
                'name' => "Kind {$i}",
                'tracker_uid' => "OVER-TAG-{$i}",
                'is_active' => true,
            ]);
            ChildLocation::create(['child_id' => $child->id, 'room_id' => $room->id]);
        }

        $response = $this->getJson('/api/v1/rooms');

        $response->assertStatus(200);
        $entry = collect($response->json())->firstWhere('id', $room->id);

        $this->assertSame(5, $entry['current_count']);
        $this->assertTrue($entry['status']['over_capacity']);
        $this->assertFalse($entry['status']['within_tolerance']);
    }

    /** @test */
    public function test_rooms_index_returns_neither_warn_nor_over_when_within_capacity()
    {
        $room = Room::create([
            'name' => 'Mini-Raum', 'area' => 'EG',
            'capacity' => 5, 'tolerance' => 2, 'is_active' => true,
        ]);

        foreach (range(1, 3) as $i) {
            $child = Child::create([
                'name' => "Kind {$i}",
                'tracker_uid' => "OK-TAG-{$i}",
                'is_active' => true,
            ]);
            ChildLocation::create(['child_id' => $child->id, 'room_id' => $room->id]);
        }

        $entry = collect($this->getJson('/api/v1/rooms')->json())->firstWhere('id', $room->id);

        $this->assertFalse($entry['status']['over_capacity']);
        $this->assertFalse($entry['status']['within_tolerance']);
    }
}
