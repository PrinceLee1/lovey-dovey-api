<?php

namespace Tests\Feature;

use App\Models\GameHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_endpoint_works_without_a_mysql_only_sql_function(): void
    {
        // Was using CONVERT_TZ() in raw SQL, which only MySQL supports —
        // this 500'd on SQLite (the test/dev driver). Not asserting a
        // specific active_days count here since that depends on "today"
        // relative to startOfWeek(); the point is it doesn't crash.
        $user = User::factory()->create();
        GameHistory::create([
            'user_id' => $user->id, 'game_id' => 'g1', 'game_title' => 'Trivia',
            'kind' => 'trivia', 'category' => 'General', 'rounds' => 1, 'skipped' => 0,
            'xp_earned' => 10, 'played_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/me/progress')
            ->assertStatus(200)
            ->assertJsonStructure(['xp', 'weekly' => ['active_days', 'goal_days', 'percent', 'week_start', 'week_end']]);
    }

    public function test_progress_counts_the_users_local_calendar_day_once_across_sources(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $now = now();

        GameHistory::create([
            'user_id' => $user->id, 'game_id' => 'g1', 'game_title' => 'Trivia',
            'kind' => 'trivia', 'category' => 'General', 'rounds' => 1, 'skipped' => 0,
            'xp_earned' => 10, 'played_at' => $now,
        ]);
        GameHistory::create([
            'user_id' => $user->id, 'game_id' => 'g2', 'game_title' => 'Charades',
            'kind' => 'charades_ai', 'category' => 'General', 'rounds' => 1, 'skipped' => 0,
            'xp_earned' => 10, 'played_at' => $now->copy()->addHour(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/me/progress')
            ->assertStatus(200);

        // Two games on the same UTC day → one active day, not two.
        $this->assertSame(1, $response->json('weekly.active_days'));
    }
}
