<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklySummaryDigest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $summary)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->summary;
        $dashboardUrl = rtrim(config('app.frontend_url'), '/').'/games';

        $message = (new MailMessage)
            ->subject('Your LoveyDovey week in review 💕')
            ->greeting("Hey {$notifiable->name}!")
            ->line("Here's what you got up to this past week:");

        if ($s['games_with_partner'] > 0) {
            $message->line("• {$s['games_with_partner']} game(s) played with {$s['partner_name']}");
        }
        if ($s['lobby_games'] > 0) {
            $message->line("• {$s['lobby_games']} game(s) played in party lobbies");
        }
        if ($s['games_total'] > 0) {
            $message->line("• {$s['xp_earned']} XP earned");
        }
        if ($s['games_with_partner'] === 0 && $s['lobby_games'] === 0) {
            $message->line("• No games played this week — your streak might miss you!");
        }

        $message->line("🔥 Current streak: {$s['current_streak']} day(s) (longest: {$s['longest_streak']})");

        if ($s['couple_streak_current'] !== null) {
            $message->line("💑 Couple streak with {$s['partner_name']}: {$s['couple_streak_current']} day(s) (longest: {$s['couple_streak_longest']})");
        }

        return $message
            ->action('See your full progress', $dashboardUrl)
            ->line('You can turn off weekly summaries anytime in Settings → Notifications.');
    }
}
