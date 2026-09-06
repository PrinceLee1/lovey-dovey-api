<?php

namespace Tests\Feature;

use App\Models\Lobby;
use App\Models\LobbyGameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LobbyGameXpTest extends TestCase
{
    use RefreshDatabase;

    private function makeLobby(User $host, array $memberUsers): Lobby
    {
        $lobby = Lobby::create([
            'name' => 'Party', 'max_players' => 6, 'entry_coins' => 0,
            'privacy' => 'Public', 'status' => 'open', 'host_id' => $host->id,
        ]);
        $lobby->members()->attach($host->id, ['role' => 'host']);
        foreach ($memberUsers as $u) {
            $lobby->members()->attach($u->id, ['role' => 'player']);
        }

        return $lobby;
    }

    public function test_ending_a_lobby_game_awards_xp_to_every_member(): void
    {
        $host = User::factory()->create(['xp' => 0]);
        $playerA = User::factory()->create(['xp' => 0]);
        $playerB = User::factory()->create(['xp' => 0]);
        $lobby = $this->makeLobby($host, [$playerA, $playerB]);

        $session = LobbyGameSession::create([
            'lobby_id' => $lobby->id, 'started_by' => $host->id, 'kind' => 'trivia', 'status' => 'active',
        ]);

        $this->actingAs($host, 'sanctum')
            ->postJson("/api/lobbies/{$lobby->code}/games/{$session->id}/end", [
                'result' => ['xpEarned' => 60, 'scores' => ['A' => 30, 'B' => 20]],
            ])
            ->assertStatus(200);

        $this->assertSame(60, $host->fresh()->xp);
        $this->assertSame(60, $playerA->fresh()->xp);
        $this->assertSame(60, $playerB->fresh()->xp);

        $this->assertDatabaseHas('game_histories', ['user_id' => $host->id, 'xp_earned' => 60, 'kind' => 'trivia']);
        $this->assertDatabaseHas('game_histories', ['user_id' => $playerA->id, 'xp_earned' => 60, 'kind' => 'trivia']);
        $this->assertDatabaseHas('game_histories', ['user_id' => $playerB->id, 'xp_earned' => 60, 'kind' => 'trivia']);
    }

    public function test_only_the_host_can_end_the_game_and_trigger_xp(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create(['xp' => 0]);
        $lobby = $this->makeLobby($host, [$player]);

        $session = LobbyGameSession::create([
            'lobby_id' => $lobby->id, 'started_by' => $host->id, 'kind' => 'trivia', 'status' => 'active',
        ]);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/lobbies/{$lobby->code}/games/{$session->id}/end", ['result' => ['xpEarned' => 60]])
            ->assertStatus(403);

        $this->assertSame(0, $player->fresh()->xp);
    }

    public function test_a_zero_xp_result_does_not_touch_anyones_xp(): void
    {
        $host = User::factory()->create(['xp' => 10]);
        $lobby = $this->makeLobby($host, []);

        $session = LobbyGameSession::create([
            'lobby_id' => $lobby->id, 'started_by' => $host->id, 'kind' => 'charades_ai', 'status' => 'active',
        ]);

        $this->actingAs($host, 'sanctum')
            ->postJson("/api/lobbies/{$lobby->code}/games/{$session->id}/end", ['result' => ['note' => 'ended early']])
            ->assertStatus(200);

        $this->assertSame(10, $host->fresh()->xp);
        $this->assertDatabaseMissing('game_histories', ['user_id' => $host->id]);
    }

    public function test_ending_early_with_an_empty_result_does_not_422(): void
    {
        // This is exactly what LobbyRoom.tsx's "End Game" button sends
        // (endActiveGame({})) — json_decode('{}', true) is an empty PHP
        // array, which `required|array` used to reject.
        $host = User::factory()->create(['xp' => 5]);
        $lobby = $this->makeLobby($host, []);

        $session = LobbyGameSession::create([
            'lobby_id' => $lobby->id, 'started_by' => $host->id, 'kind' => 'hot_seat', 'status' => 'active',
        ]);

        $this->actingAs($host, 'sanctum')
            ->postJson("/api/lobbies/{$lobby->code}/games/{$session->id}/end", ['result' => []])
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertSame('ended', $session->fresh()->status);
        $this->assertSame(5, $host->fresh()->xp);
    }
}
