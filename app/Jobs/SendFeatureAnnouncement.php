<?php

namespace App\Jobs;

use App\Models\FeatureAnnouncement;
use App\Models\User;
use App\Notifications\FeatureAnnouncementMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendFeatureAnnouncement implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $announcementId)
    {
    }

    public function handle(): void
    {
        $announcement = FeatureAnnouncement::find($this->announcementId);
        if (! $announcement) {
            return;
        }

        $announcement->update(['status' => 'sending']);

        $sent = 0;
        $failed = 0;

        User::active()
            ->where('email_news', true)
            ->chunkById(100, function ($users) use ($announcement, &$sent, &$failed) {
                foreach ($users as $user) {
                    // One user's mail failure must not stop the rest of the batch.
                    try {
                        Notification::send($user, new FeatureAnnouncementMail($announcement->subject, $announcement->body));
                        $sent++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning("Feature announcement #{$announcement->id} failed for user {$user->id}: ".$e->getMessage());
                    }
                }
            });

        $announcement->update([
            'status' => 'sent',
            'sent_count' => $sent,
            'failed_count' => $failed,
            'sent_at' => now(),
        ]);
    }
}
