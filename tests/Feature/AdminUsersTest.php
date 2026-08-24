<?php

namespace Tests\Feature;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_users_list_resolves_partner_without_the_removed_relation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        // Deliberately the user_b_id side of the pivot — activePartner()
        // exists specifically because a plain belongsToMany can't resolve
        // that direction; this is the case that used to 500.
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Partner::create([
            'user_a_id' => min($userA->id, $userB->id),
            'user_b_id' => max($userA->id, $userB->id),
            'status' => 'active',
            'started_at' => now(),
        ]);
        $solo = User::factory()->create();

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertStatus(200);

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertSame($userA->name, $byId[$userB->id]['partner']['name']);
        $this->assertSame($userB->name, $byId[$userA->id]['partner']['name']);
        $this->assertNull($byId[$solo->id]['partner']);
    }

    public function test_admin_users_no_partner_filter_excludes_both_sides_of_a_pair(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Partner::create([
            'user_a_id' => min($userA->id, $userB->id),
            'user_b_id' => max($userA->id, $userB->id),
            'status' => 'active',
            'started_at' => now(),
        ]);
        $solo = User::factory()->create();

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/users?filter=no_partner')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($solo->id));
        $this->assertFalse($ids->contains($userA->id));
        $this->assertFalse($ids->contains($userB->id));
    }

    public function test_admin_stats_counts_paired_users(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Partner::create([
            'user_a_id' => min($userA->id, $userB->id),
            'user_b_id' => max($userA->id, $userB->id),
            'status' => 'active',
            'started_at' => now(),
        ]);
        // Ended pairing shouldn't count.
        $userC = User::factory()->create();
        $userD = User::factory()->create();
        Partner::create([
            'user_a_id' => min($userC->id, $userD->id),
            'user_b_id' => max($userC->id, $userD->id),
            'status' => 'ended',
            'started_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/stats')
            ->assertStatus(200)
            ->assertJsonPath('paired_users', 2);
    }
}
