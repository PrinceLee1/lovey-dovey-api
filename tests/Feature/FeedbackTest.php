<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_submit_feedback(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/feedback', ['category' => 'idea', 'message' => 'Add a couples quiz mode!'])
            ->assertStatus(201);

        $this->assertDatabaseHas('feedback', [
            'user_id' => $user->id,
            'category' => 'idea',
            'message' => 'Add a couples quiz mode!',
            'status' => 'new',
        ]);
        $this->assertSame('new', $response->json('status'));
    }

    public function test_feedback_requires_a_message(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/feedback', ['category' => 'bug'])
            ->assertStatus(422);
    }

    public function test_category_defaults_to_other_when_omitted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/feedback', ['message' => 'Just saying hi 👋'])
            ->assertStatus(201)
            ->assertJsonPath('category', 'other');
    }

    public function test_a_non_admin_cannot_list_feedback(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/feedback')
            ->assertStatus(403);
    }

    public function test_an_admin_can_list_and_mark_feedback_reviewed(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $author = User::factory()->create(['name' => 'Jordan']);
        $feedback = Feedback::create(['user_id' => $author->id, 'category' => 'bug', 'message' => 'Spin wheel got stuck']);

        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/feedback')->assertStatus(200);
        $this->assertSame('Jordan', $list->json('data.0.user.name'));

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/feedback/{$feedback->id}", ['reviewed' => true])
            ->assertStatus(200)
            ->assertJsonPath('status', 'reviewed');
    }
}
