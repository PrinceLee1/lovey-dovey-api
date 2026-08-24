<?php

namespace App\Notifications;

use App\Models\Lobby;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PublicLobbyCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Lobby $lobby)
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
        $joinUrl = rtrim(config('app.frontend_url'), '/')."/lobby/{$this->lobby->code}";

        return (new MailMessage)
            ->subject("{$this->lobby->host->name} started a new game: {$this->lobby->name}")
            ->greeting("Hey {$notifiable->name}!")
            ->line("{$this->lobby->host->name} just opened a public game night — \"{$this->lobby->name}\".")
            ->line("Join with code: {$this->lobby->code}")
            ->action('Join the lobby', $joinUrl)
            ->line('You can turn these reminders off anytime in Settings → Notifications.');
    }
}
