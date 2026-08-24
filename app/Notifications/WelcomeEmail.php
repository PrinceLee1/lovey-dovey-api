<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dashboardUrl = rtrim(config('app.frontend_url'), '/').'/games';

        return (new MailMessage)
            ->subject('Welcome to LoveyDovey 💕')
            ->greeting("Hey {$notifiable->name}!")
            ->line("We're so glad you're here. LoveyDovey is your space for playful games, daily challenges, and streaks with the person who matters most.")
            ->line("Here's how to get started:")
            ->line('• Invite your partner from Settings so you can play together')
            ->line('• Try today\'s Daily Challenge for a quick +50 XP')
            ->line('• Host a party lobby and play Trivia or Charades with friends')
            ->action('Open LoveyDovey', $dashboardUrl)
            ->line('Glad to have you with us.');
    }
}
