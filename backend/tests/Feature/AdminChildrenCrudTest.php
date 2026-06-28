<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
    public function test_admin_can_create_child_without_tracker_uid()
    {
        $response = $this->postJson('/api/v1/admin/children', [
            'name' => 'Ohne Tracker',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Ohne Tracker')
            ->assertJsonPath('tracker_uid', null);

        $this->assertDatabaseHas('children', [
            'name' => 'Ohne Tracker',
            'tracker_uid' => null,
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

    /** @test */
    public function test_admin_can_upload_child_photo()
    {
        Storage::fake('public');

        $child = Child::create([
            'name' => 'Mit Foto',
            'tracker_uid' => 'TAG-PHOTO',
            'is_active' => true,
        ]);

        $file = UploadedFile::fake()->create('upload.jpg', 50, 'image/jpeg');

        $response = $this->postJson("/api/v1/admin/children/{$child->id}/photo", [
            'photo' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('id', $child->id)
            ->assertJsonPath('photo_url', "/storage/children/{$child->id}.jpg");

        Storage::disk('public')->assertExists("children/{$child->id}.jpg");
        $this->assertSame("/storage/children/{$child->id}.jpg", $child->fresh()->photo_url);
    }

    /** @test */
    public function test_admin_photo_upload_rejects_non_image()
    {
        Storage::fake('public');

        $child = Child::create([
            'name' => 'Bad Upload',
            'tracker_uid' => 'TAG-BAD',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/v1/admin/children/{$child->id}/photo", [
            'photo' => UploadedFile::fake()->create('not-an-image.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422);
        Storage::disk('public')->assertDirectoryEmpty('children');
    }

    /** @test */
    public function test_admin_can_delete_child_photo()
    {
        Storage::fake('public');

        $child = Child::create([
            'name' => 'Photo to Delete',
            'tracker_uid' => 'TAG-DEL-PHOTO',
            'is_active' => true,
            'photo_url' => '/storage/children/X.jpg',
        ]);

        // erst hochladen, dann loeschen
        $this->postJson("/api/v1/admin/children/{$child->id}/photo", [
            'photo' => UploadedFile::fake()->create('avatar.jpg', 50, 'image/jpeg'),
        ])->assertStatus(200);

        Storage::disk('public')->assertExists("children/{$child->id}.jpg");

        $response = $this->deleteJson("/api/v1/admin/children/{$child->id}/photo");

        $response->assertStatus(200)
            ->assertJsonPath('photo_url', null);

        Storage::disk('public')->assertMissing("children/{$child->id}.jpg");
        $this->assertNull($child->fresh()->photo_url);
    }
}
