<?php

namespace App\Notifications;

use App\Models\GameSession;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CoupleGameInvite extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public GameSession $session, public User $inviter)
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
        $joinUrl = rtrim(config('app.frontend_url'), '/')."/session/{$this->session->code}";
        $gameLabel = str_contains($this->session->kind, 'erotic') ? 'Truth or Dare (Plus)' : 'Truth or Dare';

        return (new MailMessage)
            ->subject("{$this->inviter->name} wants to play {$gameLabel} with you 💕")
            ->greeting("Hey {$notifiable->name}!")
            ->line("{$this->inviter->name} just invited you to a round of {$gameLabel}.")
            ->action('Join the game', $joinUrl)
            ->line("If you don't see it in the app yet, this link will take you straight there.");
    }
}
