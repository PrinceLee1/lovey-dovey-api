<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FeatureAnnouncementMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $subject, public string $body)
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
        $dashboardUrl = rtrim(config('app.frontend_url'), '/').'/games';

        $mail = (new MailMessage)
            ->subject($this->subject)
            ->greeting("Hey {$notifiable->name}!");

        // Preserve the admin's paragraph breaks as separate lines rather than
        // collapsing everything into one wall of text.
        foreach (preg_split('/\r?\n\r?\n/', trim($this->body)) as $paragraph) {
            $mail->line(str_replace(["\r\n", "\n"], ' ', $paragraph));
        }

        return $mail
            ->action('Open LoveyDovey', $dashboardUrl)
            ->line("You're getting this because you opted in to new features & tips — you can turn it off anytime in Settings.");
    }
}
