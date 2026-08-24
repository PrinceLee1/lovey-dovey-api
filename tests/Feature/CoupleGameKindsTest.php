<?php

namespace Tests\Feature;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoupleGameKindsTest extends TestCase
{
    use RefreshDatabase;

    private function pairUp(User $a, User $b): void
    {
        Partner::create([
            'user_a_id' => $a->id,
            'user_b_id' => $b->id,
            'status' => 'active',
            'started_at' => now(),
        ]);
    }

    private function inviteAndAccept(User $host, User $partner, string $kind): string
    {
        $code = $this->actingAs($host, 'sanctum')
            ->postJson('/api/sessions', ['kind' => $kind])
            ->json('code');
        $this->actingAs($partner, 'sanctum')->postJson("/api/sessions/{$code}/accept");

        return $code;
    }

    public function test_spice_dice_only_ever_draws_dares(): void
    {
        $host = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($host, $partner);
        $code = $this->inviteAndAccept($host, $partner, 'spice_dice');

        $spin = $this->actingAs($host, 'sanctum')
            ->postJson("/api/sessions/{$code}/action", ['type' => 'spin'])
            ->assertStatus(200);

        $this->assertSame('dare', $spin->json('state.currentType'));
        $this->assertNotEmpty($spin->json('state.currentPrompt'));

        $done = $this->actingAs($host, 'sanctum')
            ->postJson("/api/sessions/{$code}/action", ['type' => 'done'])
            ->assertStatus(200);
        $this->assertSame($partner->id, $done->json('turnUserId'));
    }

    public function test_emoji_chat_has_no_turn_gate_and_rejects_non_emoji(): void
    {
        $host = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($host, $partner);
        $code = $this->inviteAndAccept($host, $partner, 'emoji_chat');

        // Host created the session and is turn_user_id, but chat has no turns.
        $partnerMsg = $this->actingAs($partner, 'sanctum')
            ->postJson("/api/sessions/{$code}/action", ['type' => 'message', 'payload' => ['text' => '🔥🔥']])
            ->assertStatus(200);
        $this->assertCount(1, $partnerMsg->json('state.messages'));

        $hostMsg = $this->actingAs($host, 'sanctum')
            ->postJson("/api/sessions/{$code}/action", ['type' => 'message', 'payload' => ['text' => '❤️']])
            ->assertStatus(200);
        $this->assertCount(2, $hostMsg->json('state.messages'));

        $this->actingAs($host, 'sanctum')
            ->postJson("/api/sessions/{$code}/action", ['type' => 'message', 'payload' => ['text' => 'hello']])
            ->assertStatus(422);
    }

    public function test_emoji_chat_sets_an_end_time_on_accept(): void
    {
        $host = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($host, $partner);
        $code = $this->inviteAndAccept($host, $partner, 'emoji_chat');

        $show = $this->actingAs($host, 'sanctum')->getJson("/api/sessions/{$code}")->assertStatus(200);
        $this->assertNotNull($show->json('state.endsAt'));
    }

    public function test_memory_match_deck_has_matching_pairs_and_flip_is_turn_gated(): void
    {
        $host = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($host, $partner);
        $code = $this->inviteAndAccept($host, $partner, 'memory_match');

        $show = $this->actingAs($host, 'sanctum')->getJson("/api/sessions/{$code}");
        $deck = $show->json('state.deck');
        $this->assertCount(12, $deck);
        $values = array_count_values(array_column($deck, 'value'));
        foreach ($values as $count) {
            $this->assertSame(2, $count);
        }

        // Partner tries to flip on host's turn.
        $this->actingAs($partner, 'sanctum')
            ->postJson("/api/sessions/{$code}/action", ['type' => 'flip', 'payload' => ['index' => 0]])
            ->assertStatus(422);
    }

    public function test_memory_match_mismatch_passes_turn_and_match_keeps_it(): void
    {
        $host = User::factory()->create();
        $partner = User::factory()->create();
        $this->pairUp($host, $partner);
        $code = $this->inviteAndAccept($host, $partner, 'memory_match');

        $show = $this->actingAs($host, 'sanctum')->getJson("/api/sessions/{$code}");
        $deck = $show->json('state.deck');

        $firstIdx = 0;
        $matchIdx = null;
        $mismatchIdx = null;
        foreach ($deck as $i => $card) {
            if ($i === $firstIdx) continue;
            if ($card['value'] === $deck[$firstIdx]['value']) $matchIdx = $i;
            else $mismatchIdx = $i;
        }

        // Mismatch case: flip two different-valued cards -> turn passes.
        $this->actingAs($host, 'sanctum')->postJson("/api/sessions/{$code}/action", ['type' => 'flip', 'payload' => ['index' => $firstIdx]]);
        $mismatchResp = $this->actingAs($host, 'sanctum')
            ->postJson("/api/sessions/{$code}/action", ['type' => 'flip', 'payload' => ['index' => $mismatchIdx]])
            ->assertStatus(200);
        $this->assertFalse($mismatchResp->json('state.justRevealed.matched'));
        $this->assertSame($partner->id, $mismatchResp->json('turnUserId'));
    }
}
