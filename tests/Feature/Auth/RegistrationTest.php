<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\WelcomeEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_can_register(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['user', 'token']);
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    public function test_registration_sends_a_welcome_email(): void
    {
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test2@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(201);

        $user = User::where('email', 'test2@example.com')->firstOrFail();

        Notification::assertSentTo($user, WelcomeEmail::class);
    }

    public function test_new_users_get_a_14_day_plus_trial(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test3@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(201);

        $this->assertTrue($response->json('user.is_plus'));

        $user = User::where('email', 'test3@example.com')->firstOrFail();
        $this->assertFalse((bool) $user->getRawOriginal('is_plus'), 'the raw column must stay false — only the trial grants access');
        $this->assertTrue($user->trial_ends_at->isFuture());
        $this->assertTrue($user->trial_ends_at->diffInDays(now()->addDays(14)) < 1);
    }

    public function test_plus_access_expires_with_the_trial(): void
    {
        $user = User::factory()->create(['trial_ends_at' => now()->subDay()]);

        $this->assertFalse($user->is_plus);
    }

    public function test_a_real_subscriber_keeps_plus_after_their_trial_ends(): void
    {
        $user = User::factory()->create(['is_plus' => true, 'trial_ends_at' => now()->subDay()]);

        $this->assertTrue($user->is_plus);
    }
}
