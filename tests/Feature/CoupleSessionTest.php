<?php

namespace Tests\Feature;

use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoupleSessionTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(User $creator, User $partner): GameSession
    {
        $session = GameSession::create([
            'code' => 'TESTCODE',
            'kind' => 'truth_dare',
            'created_by' => $creator->id,
            'partner_user_id' => $partner->id,
            'turn_user_id' => $creator->id,
            'status' => 'active',
            'state' => ['done' => 1],
        ]);
        // round isn't mass-assignable (matches how the real controller sets
        // it: create() relies on the schema default, action() bumps it via
        // direct property assignment) — mirror that here rather than the app.
        $session->round = 3;
        $session->save();

        return $session;
    }

    public function test_show_returns_camel_case_shape_matching_broadcast_events(): void
    {
        $creator = User::factory()->create();
        $partner = User::factory()->create();
        $session = $this->makeSession($creator, $partner);

        $this->actingAs($creator)
            ->getJson("/api/sessions/{$session->code}")
            ->assertStatus(200)
            ->assertExactJson([
                'code' => 'TESTCODE',
                'kind' => 'truth_dare',
                'round' => 3,
                'turnUserId' => $creator->id,
                'status' => 'active',
                'state' => ['done' => 1],
            ]);
    }

    public function test_show_is_accessible_to_both_members_of_the_pair(): void
    {
        $creator = User::factory()->create();
        $partner = User::factory()->create();
        $session = $this->makeSession($creator, $partner);

        $this->actingAs($partner)
            ->getJson("/api/sessions/{$session->code}")
            ->assertStatus(200)
            ->assertJsonPath('turnUserId', $creator->id);
    }

    public function test_show_rejects_users_outside_the_pair(): void
    {
        $creator = User::factory()->create();
        $partner = User::factory()->create();
        $stranger = User::factory()->create();
        $session = $this->makeSession($creator, $partner);

        $this->actingAs($stranger)
            ->getJson("/api/sessions/{$session->code}")
            ->assertStatus(403);
    }
}
