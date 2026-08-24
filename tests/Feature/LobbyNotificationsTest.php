<?php

namespace Tests\Feature;

use App\Models\Lobby;
use App\Models\User;
use App\Notifications\PublicLobbyCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LobbyNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function createLobbyPayload(string $privacy = 'Public'): array
    {
        return [
            'name' => 'Friday Game Night',
            'max_players' => 6,
            'entry_coins' => 0,
            'privacy' => $privacy,
        ];
    }

    public function test_public_lobby_notifies_opted_in_users_but_not_the_host_or_opted_out(): void
    {
        Notification::fake();

        $host = User::factory()->create(['email_reminders' => true]);
        $optedIn = User::factory()->create(['email_reminders' => true]);
        $optedOut = User::factory()->create(['email_reminders' => false]);

        $this->actingAs($host)
            ->postJson('/api/lobbies', $this->createLobbyPayload('Public'))
            ->assertStatus(201);

        Notification::assertSentTo($optedIn, PublicLobbyCreated::class);
        Notification::assertNotSentTo($optedOut, PublicLobbyCreated::class);
        Notification::assertNotSentTo($host, PublicLobbyCreated::class);
    }

    public function test_private_lobby_sends_no_notifications(): void
    {
        Notification::fake();

        $host = User::factory()->create();
        $other = User::factory()->create(['email_reminders' => true]);

        $this->actingAs($host)
            ->postJson('/api/lobbies', $this->createLobbyPayload('Private'))
            ->assertStatus(201);

        Notification::assertNotSentTo($other, PublicLobbyCreated::class);
    }

    public function test_public_discovery_excludes_lobbies_hosted_by_private_profile_users(): void
    {
        $privateHost = User::factory()->create(['private_profile' => true]);
        $publicHost = User::factory()->create(['private_profile' => false]);

        Lobby::create([
            'name' => 'Hidden Party', 'max_players' => 4, 'entry_coins' => 0,
            'privacy' => 'Public', 'status' => 'open', 'host_id' => $privateHost->id,
        ]);
        Lobby::create([
            'name' => 'Visible Party', 'max_players' => 4, 'entry_coins' => 0,
            'privacy' => 'Public', 'status' => 'open', 'host_id' => $publicHost->id,
        ]);

        $response = $this->actingAs($publicHost)
            ->getJson('/api/lobbies/public')
            ->assertStatus(200);

        $names = collect($response->json())->pluck('name');
        $this->assertTrue($names->contains('Visible Party'));
        $this->assertFalse($names->contains('Hidden Party'));
    }
}
