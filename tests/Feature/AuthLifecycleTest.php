<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_login_logout_and_login_again(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $firstToken = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('token');

        $this->withToken($firstToken)->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out');

        [$firstTokenId] = explode('|', $firstToken, 2);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $firstTokenId]);

        $secondToken = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('token');

        $this->assertNotSame($firstToken, $secondToken);
        $this->withToken($secondToken)->getJson('/api/user')->assertOk();
    }

    public function test_cookie_authenticated_logout_does_not_assume_a_personal_access_token(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out');
    }
}
