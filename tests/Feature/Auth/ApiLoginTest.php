<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_records_last_login_at(): void
    {
        $user = User::factory()->create(['last_login_at' => null]);

        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(200);

        $this->assertNotNull($response->json('user.last_login_at'));
        $this->assertTrue($user->fresh()->last_login_at->isToday());
    }

    public function test_a_failed_login_does_not_record_last_login_at(): void
    {
        $user = User::factory()->create(['last_login_at' => null]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->last_login_at);
    }
}
