<?php

namespace Tests\Feature;

use App\Models\Lobby;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LobbyJoinTest extends TestCase
{
    use RefreshDatabase;

    protected function makeLobby(User $host, int $maxPlayers = 2): Lobby
    {
        $lobby = Lobby::create([
            'name' => 'Test Lobby',
            'max_players' => $maxPlayers,
            'entry_coins' => 0,
            'privacy' => 'Public',
            'host_id' => $host->id,
            'status' => 'open',
        ]);
        $lobby->members()->attach($host->id, ['role' => 'host']);

        return $lobby;
    }

    public function test_new_joiner_is_blocked_once_lobby_reaches_capacity(): void
    {
        $host = User::factory()->create();
        $lobby = $this->makeLobby($host, maxPlayers: 2);
        $second = User::factory()->create();
        $lobby->members()->attach($second->id, ['role' => 'player']);

        $third = User::factory()->create();
        $response = $this->actingAs($third, 'sanctum')->postJson("/api/lobbies/{$lobby->code}/join");

        $response->assertStatus(422)->assertJson(['message' => 'Lobby is full']);
        $this->assertFalse($lobby->members()->where('users.id', $third->id)->exists());
    }

    public function test_new_joiner_succeeds_when_there_is_room(): void
    {
        $host = User::factory()->create();
        $lobby = $this->makeLobby($host, maxPlayers: 2);

        $joiner = User::factory()->create();
        $response = $this->actingAs($joiner, 'sanctum')->postJson("/api/lobbies/{$lobby->code}/join");

        $response->assertStatus(200);
        $this->assertTrue($lobby->members()->where('users.id', $joiner->id)->exists());
    }

    public function test_existing_member_can_rejoin_a_full_lobby_without_error(): void
    {
        $host = User::factory()->create();
        $lobby = $this->makeLobby($host, maxPlayers: 2);
        $second = User::factory()->create();
        $lobby->members()->attach($second->id, ['role' => 'player']);

        // Lobby is now at capacity (2/2) — the host revisiting their own
        // lobby (e.g. via LobbyRoom's join-on-mount) must not be blocked.
        $response = $this->actingAs($host, 'sanctum')->postJson("/api/lobbies/{$lobby->code}/join");

        $response->assertStatus(200);
        $pivot = $lobby->members()->where('users.id', $host->id)->first()->pivot;
        $this->assertSame('host', $pivot->role);
    }
}
