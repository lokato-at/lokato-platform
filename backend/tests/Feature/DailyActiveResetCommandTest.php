<?php

namespace Tests\Feature;

use App\Models\AppRuntimeState;
use App\Models\Child;
use App\Models\ChildLocation;
use App\Models\Room;
use App\Support\SseChangeSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DailyActiveResetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_clears_locations_and_flips_active_flag(): void
    {
        $room = Room::create([
            'name' => 'Garten', 'area' => 'A',
            'capacity' => 20, 'tolerance' => 2, 'is_active' => true,
        ]);

        $child1 = Child::create(['name' => 'A', 'tracker_uid' => 'T1', 'is_active' => true]);
        $child2 = Child::create(['name' => 'B', 'tracker_uid' => 'T2', 'is_active' => true]);
        ChildLocation::create(['child_id' => $child1->id, 'room_id' => $room->id, 'updated_at' => now()]);
        ChildLocation::create(['child_id' => $child2->id, 'room_id' => $room->id, 'updated_at' => now()]);

        // Cache vorher leeren, damit der Bump-Effekt isoliert geprüft werden kann
        Cache::forget('sse:last_change_at');

        $exitCode = $this->artisan('children:daily-active-reset')->run();

        $this->assertSame(0, $exitCode, 'command must succeed');

        // children.is_active flippt auf false
        $this->assertFalse((bool) $child1->fresh()->is_active);
        $this->assertFalse((bool) $child2->fresh()->is_active);

        // child_locations ist leer
        $this->assertSame(0, ChildLocation::count(), 'child_locations should be cleared');

        // app_runtime_state-Keys sind gesetzt
        $this->assertNotNull(AppRuntimeState::where('state_key', 'last_daily_reset_date')->first());
        $this->assertNotNull(AppRuntimeState::where('state_key', 'last_daily_reset_at')->first());

        // SseChangeSignal::bump() wurde aufgerufen — Cache-Key existiert jetzt
        $this->assertGreaterThan(0.0, app(SseChangeSignal::class)->lastChangeAt());
    }

    public function test_reset_is_safe_with_empty_database(): void
    {
        // Keine Kinder, keine Locations — Command muss trotzdem durchlaufen
        $exitCode = $this->artisan('children:daily-active-reset')->run();

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, Child::count());
        $this->assertSame(0, ChildLocation::count());
    }
}
