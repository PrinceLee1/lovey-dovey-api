<?php

namespace Tests\Feature;

use App\Models\GameSession;
use App\Models\Partner;
use App\Models\User;
use App\Notifications\CoupleGameInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CoupleGameInviteFlowTest extends TestCase
{
    use RefreshDatabase;

    private function pairUp(User $a, User $b): Partner
    {
        return Partner::create([
            'user_a_id' => $a->id,
            'user_b_id' => $b->id,
            'status' => 'active',
            'started_at' => now(),
        ]);
    }

    public function test_invite_creates_a_waiting_session_and_emails_the_partner(): void
    {
        Notification::fake();

        $me = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($me, $partner);

        $response = $this->actingAs($me, 'sanctum')->postJson('/api/sessions', ['kind' => 'truth_dare']);

        $response->assertStatus(201)->assertJsonPath('status', 'waiting');

        $this->assertDatabaseHas('game_sessions', [
            'code' => $response->json('code'),
            'status' => 'waiting',
            'created_by' => $me->id,
            'partner_user_id' => $partner->id,
        ]);

        Notification::assertSentTo($partner, CoupleGameInvite::class);
    }

    public function test_invite_fails_without_an_active_partner(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me, 'sanctum')->postJson('/api/sessions', ['kind' => 'truth_dare'])
            ->assertStatus(404);
    }

    public function test_partner_accepting_activates_the_session(): void
    {
        $me = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($me, $partner);

        $code = $this->actingAs($me, 'sanctum')
            ->postJson('/api/sessions', ['kind' => 'truth_dare'])
            ->json('code');

        $this->actingAs($partner, 'sanctum')
            ->postJson("/api/sessions/{$code}/accept")
            ->assertStatus(200)
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('game_sessions', ['code' => $code, 'status' => 'active']);
    }

    public function test_action_is_rejected_while_session_is_still_waiting(): void
    {
        $me = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($me, $partner);

        $code = $this->actingAs($me, 'sanctum')
            ->postJson('/api/sessions', ['kind' => 'truth_dare'])
            ->json('code');

        $this->actingAs($me, 'sanctum')
            ->postJson("/api/sessions/{$code}/action", ['type' => 'spin'])
            ->assertStatus(422);
    }

    public function test_only_the_current_turn_player_can_spin(): void
    {
        $me = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($me, $partner);

        $code = $this->actingAs($me, 'sanctum')
            ->postJson('/api/sessions', ['kind' => 'truth_dare'])
            ->json('code');
        $this->actingAs($partner, 'sanctum')->postJson("/api/sessions/{$code}/accept");

        // $me created the session and starts as turn_user_id — partner must be blocked.
        $this->actingAs($partner, 'sanctum')
            ->postJson("/api/sessions/{$code}/action", ['type' => 'spin'])
            ->assertStatus(422);

        $spin = $this->actingAs($me, 'sanctum')
            ->postJson("/api/sessions/{$code}/action", ['type' => 'spin'])
            ->assertStatus(200);

        $this->assertSame('prompt', $spin->json('state.phase'));
        $this->assertContains($spin->json('state.currentType'), ['truth', 'dare']);
        $this->assertNotEmpty($spin->json('state.currentPrompt'));
    }

    public function test_done_awards_xp_and_passes_the_turn(): void
    {
        $me = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($me, $partner);

        $code = $this->actingAs($me, 'sanctum')
            ->postJson('/api/sessions', ['kind' => 'truth_dare'])
            ->json('code');
        $this->actingAs($partner, 'sanctum')->postJson("/api/sessions/{$code}/accept");
        $this->actingAs($me, 'sanctum')->postJson("/api/sessions/{$code}/action", ['type' => 'spin']);

        $done = $this->actingAs($me, 'sanctum')
            ->postJson("/api/sessions/{$code}/action", ['type' => 'done'])
            ->assertStatus(200);

        $this->assertSame('picking', $done->json('state.phase'));
        $this->assertSame(10, $done->json('state.xp'));
        $this->assertSame($partner->id, $done->json('turnUserId'));
    }

    public function test_either_player_can_finish_regardless_of_turn(): void
    {
        $me = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($me, $partner);

        $code = $this->actingAs($me, 'sanctum')
            ->postJson('/api/sessions', ['kind' => 'truth_dare'])
            ->json('code');
        $this->actingAs($partner, 'sanctum')->postJson("/api/sessions/{$code}/accept");

        // It's $me's turn, but $partner ends the session anyway.
        $this->actingAs($partner, 'sanctum')
            ->postJson("/api/sessions/{$code}/action", ['type' => 'finish'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'ended');
    }

    public function test_a_stranger_cannot_accept_or_action_the_session(): void
    {
        $me = User::factory()->create();
        $partner = User::factory()->create();
        $stranger = User::factory()->create();
        $this->pairUp($me, $partner);

        $code = $this->actingAs($me, 'sanctum')
            ->postJson('/api/sessions', ['kind' => 'truth_dare'])
            ->json('code');

        $this->actingAs($stranger, 'sanctum')->postJson("/api/sessions/{$code}/accept")->assertStatus(403);
        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/sessions/{$code}/action", ['type' => 'finish'])
            ->assertStatus(403);
    }
}
