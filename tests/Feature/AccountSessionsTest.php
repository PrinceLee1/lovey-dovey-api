<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_and_login_capture_device_info_on_the_token(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], ['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) AppleWebKit/605.1.15 Safari/605.1.15'])
            ->assertStatus(201);

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $token = $user->tokens()->first();

        $this->assertNotNull($token->ip_address);
        $this->assertStringContainsString('Safari', $token->user_agent);
    }

    public function test_sessions_lists_all_tokens_and_flags_the_current_one(): void
    {
        $user = User::factory()->create();
        $keptToken = $user->createToken('web')->plainTextToken;
        $user->createToken('other-device')->accessToken->forceFill([
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS) CriOS/1.0 Safari/605.1',
            'ip_address' => '102.88.24.9',
        ])->save();

        $response = $this->withHeader('Authorization', "Bearer {$keptToken}")
            ->getJson('/api/account/sessions')
            ->assertStatus(200);

        $sessions = $response->json('sessions');
        $this->assertCount(2, $sessions);

        $current = collect($sessions)->firstWhere('is_current', true);
        $this->assertNotNull($current);

        $other = collect($sessions)->firstWhere('is_current', false);
        $this->assertSame('iPhone Chrome', $other['device']);
        $this->assertSame('102.88.24.9', $other['ip_address']);
    }

    public function test_revoke_session_deletes_the_target_token_only(): void
    {
        $user = User::factory()->create();
        $keptToken = $user->createToken('web')->plainTextToken;
        $other = $user->createToken('other-device')->accessToken;

        $this->withHeader('Authorization', "Bearer {$keptToken}")
            ->deleteJson("/api/account/sessions/{$other->id}")
            ->assertStatus(200)
            ->assertJson(['ok' => true]);

        $this->assertCount(1, $user->fresh()->tokens);
    }

    public function test_revoke_session_refuses_to_revoke_the_current_token(): void
    {
        $user = User::factory()->create();
        $newToken = $user->createToken('web');

        $this->withHeader('Authorization', "Bearer {$newToken->plainTextToken}")
            ->deleteJson("/api/account/sessions/{$newToken->accessToken->id}")
            ->assertStatus(422);

        $this->assertCount(1, $user->fresh()->tokens);
    }

    public function test_revoke_session_rejects_another_users_token(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $strangerToken = $stranger->createToken('web')->accessToken;

        $userToken = $user->createToken('web')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$userToken}")
            ->deleteJson("/api/account/sessions/{$strangerToken->id}")
            ->assertStatus(404);

        $this->assertCount(1, $stranger->fresh()->tokens);
    }
}
