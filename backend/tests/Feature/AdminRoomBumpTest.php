<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use App\Support\SseChangeSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ohne SSE-Bump bleibt der Cache-Gate kalt und Dashboards sehen Aenderungen
 * erst beim naechsten Scan (Drift).
 */
class AdminRoomBumpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create([
            'name' => 'Admin',
            'email' => 'admin-bump@lokato.test',
            'password' => bcrypt('test-pass'),
        ]));

        Cache::forget('sse:last_change_at');
        Cache::forget('sse:last_children_change_at');
    }

    /** @test */
    public function test_room_create_bumps_children_signal_so_new_room_appears_live(): void
    {
        $beforeChildren = app(SseChangeSignal::class)->lastChildrenChangeAt();

        $response = $this->postJson('/api/v1/admin/rooms', [
            'name' => 'Neuer Raum',
            'area' => 'EG',
            'capacity' => 10,
            'tolerance' => 2,
            'is_active' => true,
        ]);

        $response->assertStatus(201);

        $afterChildren = app(SseChangeSignal::class)->lastChildrenChangeAt();
        $this->assertGreaterThan($beforeChildren, $afterChildren, 'store() must bump the children signal');
    }

    /** @test */
    public function test_room_update_bumps_change_signal_so_capacity_change_is_picked_up_live(): void
    {
        $room = Room::create([
            'name' => 'Raum',
            'area' => 'EG',
            'capacity' => 5,
            'tolerance' => 2,
            'is_active' => true,
        ]);

        $before = app(SseChangeSignal::class)->lastChangeAt();

        $response = $this->patchJson("/api/v1/admin/rooms/{$room->id}", [
            'capacity' => 3,
        ]);

        $response->assertStatus(200);

        $after = app(SseChangeSignal::class)->lastChangeAt();
        $this->assertGreaterThan($before, $after, 'update() must bump the change signal');
    }

    /** @test */
    public function test_daily_reset_uses_bump_children_not_just_bump(): void
    {
        $beforeChildren = app(SseChangeSignal::class)->lastChildrenChangeAt();

        $this->artisan('children:daily-active-reset')->assertSuccessful();

        $afterChildren = app(SseChangeSignal::class)->lastChildrenChangeAt();
        $this->assertGreaterThan($beforeChildren, $afterChildren, 'reset must bump the children signal');
    }
}
