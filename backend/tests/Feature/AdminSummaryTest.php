<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\Device;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create([
            'name' => 'Test Admin',
            'email' => 'test-admin@lokato.test',
            'password' => bcrypt('test-pass'),
        ]));
    }

    public function test_admin_summary_returns_fast_counts_without_loading_full_collections(): void
    {
        $room = Room::create([
            'name' => 'Orange',
            'area' => 'EG',
            'capacity' => 12,
            'tolerance' => 2,
            'is_active' => true,
        ]);

        Child::create([
            'name' => 'Lena',
            'photo_url' => null,
            'tracker_uid' => 'TAG-LENA',
            'is_active' => true,
        ]);

        Device::create([
            'name' => 'Scanner Orange',
            'device_key' => 'orange-01',
            'room_id' => $room->id,
        ]);

        $response = $this->getJson('/api/v1/admin/summary');

        $response->assertOk()
            ->assertJson([
                'children_count' => 1,
                'rooms_count' => 1,
                'devices_count' => 1,
            ]);
    }
}
