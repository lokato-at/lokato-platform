<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildLocation;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rooms_index_can_inline_children_to_avoid_n_plus_one_fetches(): void
    {
        $room = Room::create([
            'name' => 'Sonnenraum',
            'area' => 'EG',
            'capacity' => 12,
            'tolerance' => 2,
            'is_active' => true,
        ]);

        $child = Child::create([
            'name' => 'Mia',
            'photo_url' => null,
            'tracker_uid' => 'TAG-MIA',
            'is_active' => true,
        ]);

        ChildLocation::create([
            'child_id' => $child->id,
            'room_id' => $room->id,
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/rooms?include_children=1');

        $response->assertOk()
            ->assertHeader('X-Response-Time-ms')
            ->assertJsonPath('0.id', $room->id)
            ->assertJsonPath('0.current_count', 1)
            ->assertJsonPath('0.children.0.id', $child->id)
            ->assertJsonPath('0.children.0.name', 'Mia');
    }

    public function test_children_index_supports_room_and_limit_filters_without_breaking_payload_shape(): void
    {
        $roomA = Room::create([
            'name' => 'Gelb',
            'area' => 'EG',
            'capacity' => 10,
            'tolerance' => 2,
            'is_active' => true,
        ]);
        $roomB = Room::create([
            'name' => 'Blau',
            'area' => 'OG',
            'capacity' => 10,
            'tolerance' => 2,
            'is_active' => true,
        ]);

        $anna = Child::create([
            'name' => 'Anna',
            'photo_url' => null,
            'tracker_uid' => 'TAG-ANNA',
            'is_active' => true,
        ]);
        $berta = Child::create([
            'name' => 'Berta',
            'photo_url' => null,
            'tracker_uid' => 'TAG-BERTA',
            'is_active' => true,
        ]);

        ChildLocation::create(['child_id' => $anna->id, 'room_id' => $roomA->id, 'updated_at' => now()]);
        ChildLocation::create(['child_id' => $berta->id, 'room_id' => $roomB->id, 'updated_at' => now()]);

        $response = $this->getJson('/api/v1/children?room_id='.$roomA->id.'&limit=1');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $anna->id)
            ->assertJsonPath('0.location.room_id', $roomA->id);
    }
}
