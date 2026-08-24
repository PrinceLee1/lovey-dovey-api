<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_is_idempotent_within_the_same_day(): void
    {
        $user = User::factory()->create();

        // A second call the same day used to hit the (user_id, for_date)
        // unique constraint — the for_date lookup didn't match the format
        // Eloquent actually stored it in, so it always tried to re-insert.
        $first = $this->actingAs($user)->getJson('/api/daily-challenge')->assertStatus(200);
        $second = $this->actingAs($user)->getJson('/api/daily-challenge')->assertStatus(200);

        $this->assertSame($first->json('challenge.title'), $second->json('challenge.title'));
        $this->assertDatabaseCount('daily_challenges', 1);
    }

    public function test_complete_finds_todays_row_after_show_created_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/daily-challenge')->assertStatus(200);

        $this->actingAs($user)
            ->postJson('/api/daily-challenge/complete')
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }
}
