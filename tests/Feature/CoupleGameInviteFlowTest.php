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

    public function test_invite_email_names_the_actual_game_kind(): void
    {
        Notification::fake();

        $me = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($me, $partner);

        $this->actingAs($me, 'sanctum')->postJson('/api/sessions', ['kind' => 'spice_dice'])->assertStatus(201);

        Notification::assertSentTo($partner, CoupleGameInvite::class, function (CoupleGameInvite $notification) use ($partner) {
            $mail = $notification->toMail($partner);

            return str_contains($mail->subject, 'Spice Dice') && ! str_contains($mail->subject, 'Truth or Dare');
        });
    }

    public function test_reinviting_the_same_kind_resumes_the_existing_session_instead_of_making_a_new_one(): void
    {
        $me = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($me, $partner);

        $first = $this->actingAs($me, 'sanctum')->postJson('/api/sessions', ['kind' => 'truth_dare'])->assertStatus(201);

        // Simulates: player hit the browser back button mid-invite and re-clicked
        // the same game tile — this must resume the existing session, not mint
        // a fresh one (that was the reported "game restarts" bug).
        $second = $this->actingAs($me, 'sanctum')->postJson('/api/sessions', ['kind' => 'truth_dare'])->assertStatus(200);

        $this->assertSame($first->json('code'), $second->json('code'));
        $this->assertSame(1, GameSession::where('created_by', $me->id)->count());
    }

    public function test_reinviting_still_resumes_the_pairs_active_session_after_the_partner_accepts(): void
    {
        $me = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($me, $partner);

        $code = $this->actingAs($me, 'sanctum')->postJson('/api/sessions', ['kind' => 'truth_dare'])->json('code');
        $this->actingAs($partner, 'sanctum')->postJson("/api/sessions/{$code}/accept")->assertStatus(200);

        // Either side re-clicking the tile mid-game should land back on the
        // same active session, not abandon progress for a fresh one.
        $resumed = $this->actingAs($me, 'sanctum')->postJson('/api/sessions', ['kind' => 'truth_dare'])->assertStatus(200);
        $this->assertSame($code, $resumed->json('code'));
        $this->assertSame('active', $resumed->json('status'));
    }

    public function test_a_different_game_kind_still_starts_a_new_session(): void
    {
        $me = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($me, $partner);

        $this->actingAs($me, 'sanctum')->postJson('/api/sessions', ['kind' => 'truth_dare'])->assertStatus(201);
        $second = $this->actingAs($me, 'sanctum')->postJson('/api/sessions', ['kind' => 'memory_match'])->assertStatus(201);

        $this->assertSame('memory_match', $second->json('kind'));
        $this->assertSame(2, GameSession::where('created_by', $me->id)->count());
    }

    public function test_ended_sessions_do_not_block_starting_a_fresh_one(): void
    {
        $me = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($me, $partner);

        $code = $this->actingAs($me, 'sanctum')->postJson('/api/sessions', ['kind' => 'truth_dare'])->json('code');
        $this->actingAs($partner, 'sanctum')->postJson("/api/sessions/{$code}/accept");
        $this->actingAs($me, 'sanctum')->postJson("/api/sessions/{$code}/action", ['type' => 'finish'])->assertStatus(200);

        $fresh = $this->actingAs($me, 'sanctum')->postJson('/api/sessions', ['kind' => 'truth_dare'])->assertStatus(201);
        $this->assertNotSame($code, $fresh->json('code'));
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
