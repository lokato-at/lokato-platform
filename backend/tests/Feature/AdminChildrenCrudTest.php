<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminChildrenCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Admin endpoints are auth:sanctum-protected; authenticate as a fresh
        // user for every test so the CRUD assertions can run.
        Sanctum::actingAs(User::create([
            'name' => 'Test Admin',
            'email' => 'test-admin@lokato.test',
            'password' => bcrypt('test-pass'),
        ]));
    }

    /** @test */
    public function test_admin_can_create_child()
    {
        $payload = [
            'name'        => 'Max Mustermann',
            'tracker_uid' => 'TAG-1234',
            'is_active'   => true,
        ];

        $response = $this->postJson('/api/v1/admin/children', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Max Mustermann')
            ->assertJsonPath('tracker_uid', 'TAG-1234');

        $this->assertDatabaseHas('children', [
            'name'        => 'Max Mustermann',
            'tracker_uid' => 'TAG-1234',
        ]);
    }

    /** @test */
    public function test_admin_can_update_child()
    {
        $child = Child::create([
            'name'        => 'Ben Alt',
            'photo_url'   => null,
            'tracker_uid' => 'TAG-999',
            'is_active'   => true,
        ]);

        $response = $this->patchJson("/api/v1/admin/children/{$child->id}", [
            'name'      => 'Ben Neu',
            'is_active' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Ben Neu')
            ->assertJsonPath('is_active', false);

        $this->assertDatabaseHas('children', [
            'id'        => $child->id,
            'name'      => 'Ben Neu',
            'is_active' => false,
        ]);
    }

    /** @test */
    public function test_admin_can_delete_child()
    {
        $child = Child::create([
            'name'        => 'Zum Löschen',
            'photo_url'   => null,
            'tracker_uid' => 'TAG-DEL',
            'is_active'   => true,
        ]);

        $response = $this->deleteJson("/api/v1/admin/children/{$child->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('children', [
            'id' => $child->id,
        ]);
    }
}
