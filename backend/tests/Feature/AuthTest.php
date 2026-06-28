<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $password = 'correct-horse-battery-staple'): User
    {
        return User::create([
            'name' => 'Test Admin',
            'email' => 'admin@lokato.test',
            'password' => Hash::make($password),
        ]);
    }

    public function test_login_returns_token_on_valid_credentials(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.email', $user->email);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        $this->makeUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@lokato.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_routes_require_authentication(): void
    {
        $response = $this->getJson('/api/v1/admin/summary');
        $response->assertStatus(401);
    }

    public function test_admin_routes_accept_valid_token(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/summary');

        $response->assertStatus(200);
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('test-device')->plainTextToken;

        $logout = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');
        $logout->assertStatus(200);

        // The personal_access_token row must be deleted; a fresh request with
        // the same bearer must then fail authentication. Verify via the DB to
        // sidestep Sanctum's per-request user cache.
        $this->assertSame(0, \Laravel\Sanctum\PersonalAccessToken::count());
    }

    public function test_public_endpoints_remain_unauthenticated(): void
    {
        // /api/health, /api/v1/rooms etc. stay open for the tablet views
        $this->getJson('/api/health')->assertStatus(200);
        $this->getJson('/api/v1/rooms')->assertStatus(200);
    }
}
