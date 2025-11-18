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
}
