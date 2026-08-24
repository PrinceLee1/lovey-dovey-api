<?php

namespace Tests\Feature;

use App\Models\GameHistory;
use App\Models\Lobby;
use App\Models\LobbyGameSession;
use App\Models\Partner;
use App\Models\User;
use App\Notifications\WeeklySummaryDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WeeklySummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_summary_counts_couple_and_lobby_games_within_the_window(): void
    {
        $user = User::factory()->create(['streak_current' => 3, 'streak_longest' => 5]);
        $partner = User::factory()->create();

        // couple_streak_current/longest aren't mass-assignable — StreakService
        // sets them via forceFill(), matching the real app flow.
        Partner::create([
            'user_a_id' => min($user->id, $partner->id),
            'user_b_id' => max($user->id, $partner->id),
            'status' => 'active',
            'started_at' => now(),
        ])->forceFill(['couple_streak_current' => 2, 'couple_streak_longest' => 4])->save();

        // In-window couple game
        GameHistory::create([
            'user_id' => $user->id, 'partner_user_id' => $partner->id,
            'game_id' => 'g1', 'game_title' => 'Truth or Dare', 'kind' => 'truth_dare', 'category' => 'Romantic',
            'rounds' => 5, 'skipped' => 0, 'xp_earned' => 50, 'played_at' => now()->subDays(2),
        ]);
        // Out-of-window couple game (shouldn't count)
        GameHistory::create([
            'user_id' => $user->id, 'partner_user_id' => $partner->id,
            'game_id' => 'g2', 'game_title' => 'Truth or Dare', 'kind' => 'truth_dare', 'category' => 'Romantic',
            'rounds' => 5, 'skipped' => 0, 'xp_earned' => 999, 'played_at' => now()->subDays(20),
        ]);

        $lobby = Lobby::create([
            'name' => 'Party', 'max_players' => 4, 'entry_coins' => 0,
            'privacy' => 'Public', 'status' => 'open', 'host_id' => $user->id,
        ]);
        $lobby->members()->attach($user->id, ['role' => 'host']);
        LobbyGameSession::create([
            'lobby_id' => $lobby->id, 'started_by' => $user->id, 'kind' => 'trivia',
            'status' => 'ended', 'started_at' => now()->subDays(1), 'ended_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/me/weekly-summary')
            ->assertStatus(200);

        $response->assertJsonPath('games_with_partner', 1)
            ->assertJsonPath('games_total', 1)
            ->assertJsonPath('lobby_games', 1)
            ->assertJsonPath('xp_earned', 50)
            ->assertJsonPath('current_streak', 3)
            ->assertJsonPath('longest_streak', 5)
            ->assertJsonPath('couple_streak_current', 2)
            ->assertJsonPath('partner_name', $partner->name);

        $this->assertCount(7, $response->json('daily'));
    }

    public function test_weekly_summary_omits_couple_streak_when_unpaired(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/me/weekly-summary')
            ->assertStatus(200)
            ->assertJsonPath('couple_streak_current', null)
            ->assertJsonPath('partner_name', null);
    }

    public function test_digest_command_only_emails_opted_in_users_with_activity(): void
    {
        Notification::fake();

        $active = User::factory()->create(['weekly_summary' => true]);
        GameHistory::create([
            'user_id' => $active->id, 'game_id' => 'g1', 'game_title' => 'Trivia',
            'kind' => 'trivia', 'category' => 'General', 'rounds' => 3, 'skipped' => 0,
            'xp_earned' => 30, 'played_at' => now()->subDay(),
        ]);

        $optedOut = User::factory()->create(['weekly_summary' => false]);
        $noActivity = User::factory()->create(['weekly_summary' => true]);

        $this->artisan('app:send-weekly-summary-digest')->assertSuccessful();

        Notification::assertSentTo($active, WeeklySummaryDigest::class);
        Notification::assertNotSentTo($optedOut, WeeklySummaryDigest::class);
        Notification::assertNotSentTo($noActivity, WeeklySummaryDigest::class);
    }
}
