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

    public function test_delete_account_is_a_soft_close_not_a_row_delete(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/user', ['password' => 'password'])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);

        // Row stays — this is intentionally not a hard delete.
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'deleted']);

        // But it's functionally gone: no tokens survive, and login is blocked.
        $this->assertCount(0, $user->fresh()->tokens);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(403);
    }

    public function test_delete_account_ends_active_partner_pairing(): void
    {
        $user = User::factory()->create();
        $partner = User::factory()->create();
        Partner::create([
            'user_a_id' => min($user->id, $partner->id),
            'user_b_id' => max($user->id, $partner->id),
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/user', ['password' => 'password'])
            ->assertStatus(200);

        $this->assertDatabaseHas('partners', [
            'user_a_id' => min($user->id, $partner->id),
            'user_b_id' => max($user->id, $partner->id),
            'status' => 'ended',
        ]);
    }

    public function test_admin_cannot_self_delete(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->deleteJson('/api/user', ['password' => 'password'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'status' => 'active']);
    }

    public function test_deactivated_and_deleted_users_cannot_log_in(): void
    {
        $deactivated = User::factory()->create(['status' => 'deactivated']);
        $deleted = User::factory()->create(['status' => 'deleted']);

        $this->postJson('/api/login', ['email' => $deactivated->email, 'password' => 'password'])
            ->assertStatus(403);

        $this->postJson('/api/login', ['email' => $deleted->email, 'password' => 'password'])
            ->assertStatus(403);
    }

    public function test_me_returns_real_json_booleans_not_0_1(): void
    {
        // Uncast, these serialize as raw ints — several frontend call sites
        // do `{user?.is_admin && <Jsx/>}`, and 0 && <Jsx/> in JSX renders
        // the literal text "0" (React only skips false/null/undefined).
        $user = User::factory()->create(['is_admin' => false, 'is_plus' => false, 'is_active' => true]);

        $response = $this->actingAs($user)->getJson('/api/me')->assertStatus(200);

        $this->assertSame(false, $response->json('is_admin'));
        $this->assertSame(false, $response->json('is_plus'));
        $this->assertSame(true, $response->json('is_active'));
    }

    public function test_update_prefs_persists_and_returns_booleans(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/user/prefs', [
                'email_news' => false,
                'email_reminders' => false,
                'weekly_summary' => true,
                'private_profile' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('email_news', false)
            ->assertJsonPath('email_reminders', false)
            ->assertJsonPath('weekly_summary', true)
            ->assertJsonPath('private_profile', true);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email_news' => false,
            'private_profile' => true,
        ]);
    }
}
