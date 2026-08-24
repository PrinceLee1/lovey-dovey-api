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

    /**
     * Keep in sync with GAME_META in the frontend's src/pages/Session.tsx.
     */
    private const GAME_LABELS = [
        'truth_dare' => 'Truth or Dare',
        'truth_dare_erotic' => 'Truth or Dare · Plus',
        'spice_dice' => 'Spice Dice',
        'emoji_chat' => 'Emoji-Only Chat',
        'memory_match' => 'Memory Match',
    ];

    public function toMail(object $notifiable): MailMessage
    {
        $joinUrl = rtrim(config('app.frontend_url'), '/')."/session/{$this->session->code}";
        $gameLabel = self::GAME_LABELS[$this->session->kind] ?? 'a game';

        return (new MailMessage)
            ->subject("{$this->inviter->name} wants to play {$gameLabel} with you 💕")
            ->greeting("Hey {$notifiable->name}!")
            ->line("{$this->inviter->name} just invited you to a round of {$gameLabel}.")
            ->action('Join the game', $joinUrl)
            ->line("If you don't see it in the app yet, this link will take you straight there.");
    }
}
