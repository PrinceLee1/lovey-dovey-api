<?php

namespace Tests\Feature;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_resolves_partner_regardless_of_pivot_direction(): void
    {
        $userA = User::factory()->create(); // will be the smaller id -> user_a_id
        $userB = User::factory()->create(); // the larger id -> user_b_id

        Partner::create([
            'user_a_id' => min($userA->id, $userB->id),
            'user_b_id' => max($userA->id, $userB->id),
            'status' => 'active',
            'started_at' => now(),
        ]);

        // The user stored as user_b_id is the one the old belongsToMany
        // relation silently failed to resolve a partner for.
        $this->actingAs($userB)
            ->getJson('/api/me')
            ->assertStatus(200)
            ->assertJsonPath('partner.0.id', $userA->id)
            ->assertJsonPath('partner.0.name', $userA->name)
            ->assertJsonMissingPath('partner.0.password');

        $this->actingAs($userA)
            ->getJson('/api/me')
            ->assertStatus(200)
            ->assertJsonPath('partner.0.id', $userB->id);
    }

    public function test_me_returns_empty_partner_when_unpaired(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertStatus(200)
            ->assertJsonPath('partner', []);
    }

    public function test_logout_others_revokes_other_tokens_but_keeps_current(): void
    {
        $user = User::factory()->create();
        $keptToken = $user->createToken('current')->plainTextToken;
        $user->createToken('other-device');

        $this->assertCount(2, $user->tokens);

        $this->withHeader('Authorization', "Bearer {$keptToken}")
            ->postJson('/api/logout-others')
            ->assertStatus(200);

        $user->refresh();
        $this->assertCount(1, $user->tokens);
        $this->assertSame('current', $user->tokens->first()->name);
    }

    public function test_delete_account_requires_correct_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson('/api/user', ['password' => 'wrong-password'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_delete_account_deletes_user_and_revokes_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/user', ['password' => 'password'])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_admin_cannot_self_delete(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->deleteJson('/api/user', ['password' => 'password'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
