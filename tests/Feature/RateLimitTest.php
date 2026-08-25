<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_login_attempts_are_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
                ->assertStatus(422);
        }

        // The 6th attempt within a minute, from the same IP, should be throttled.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_repeated_registration_attempts_are_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/register', [
                'name' => 'User', 'email' => "user{$i}@example.com",
                'password' => 'password', 'password_confirmation' => 'password',
            ])->assertStatus(201);
        }

        $this->postJson('/api/register', [
            'name' => 'User', 'email' => 'user6@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertStatus(429);
    }
}
