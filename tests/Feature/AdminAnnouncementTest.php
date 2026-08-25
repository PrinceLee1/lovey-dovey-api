<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\FeatureAnnouncementMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_admin_cannot_send_an_announcement(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/announcements', ['subject' => 'Hi', 'body' => 'New stuff!'])
            ->assertStatus(403);
    }

    public function test_sending_an_announcement_requires_subject_and_body(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/announcements', [])
            ->assertStatus(422);
    }

    public function test_announcement_only_reaches_opted_in_active_users(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['is_admin' => true, 'email_news' => false]);
        $optedIn = User::factory()->create(['email_news' => true, 'status' => 'active']);
        $optedOut = User::factory()->create(['email_news' => false, 'status' => 'active']);
        $deactivatedButOptedIn = User::factory()->create(['email_news' => true, 'status' => 'deactivated']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/announcements', [
                'subject' => 'New Spice Dice game! 🎲',
                'body' => "We just shipped Spice Dice.\n\nGo try it with your partner tonight!",
            ])
            ->assertStatus(201);

        $this->assertSame(1, $response->json('recipients_count'));
        $this->assertSame(1, $response->json('sent_count'));
        $this->assertSame('sent', $response->json('status'));

        Notification::assertSentTo($optedIn, FeatureAnnouncementMail::class);
        Notification::assertNotSentTo($optedOut, FeatureAnnouncementMail::class);
        Notification::assertNotSentTo($deactivatedButOptedIn, FeatureAnnouncementMail::class);
        Notification::assertNotSentTo($admin, FeatureAnnouncementMail::class);
    }

    public function test_index_lists_past_announcements_and_the_eligible_count(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_news' => false]);
        User::factory()->count(3)->create(['email_news' => true, 'status' => 'active']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/announcements', [
            'subject' => 'Weekly tip', 'body' => 'Try the new chat sidebar!',
        ])->assertStatus(201);

        $index = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/announcements')->assertStatus(200);

        $this->assertCount(1, $index->json('data'));
        $this->assertSame('Weekly tip', $index->json('data.0.subject'));
        $this->assertSame($admin->name, $index->json('data.0.sender.name'));
        $this->assertSame(3, $index->json('eligible_recipients'));
    }
}
