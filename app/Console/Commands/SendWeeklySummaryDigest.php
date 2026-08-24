<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\WeeklySummaryDigest;
use App\Support\WeeklySummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendWeeklySummaryDigest extends Command
{
    protected $signature = 'app:send-weekly-summary-digest';

    protected $description = 'Email the weekly games/streak summary to users who opted in';

    public function handle(): int
    {
        $sent = 0;

        User::where('status', 'active')
            ->where('weekly_summary', true)
            ->chunkById(100, function ($users) use (&$sent) {
                foreach ($users as $user) {
                    $summary = WeeklySummaryService::build($user);

                    // Skip users with nothing to report — an empty digest isn't useful.
                    if ($summary['games_total'] === 0 && $summary['lobby_games'] === 0) {
                        continue;
                    }

                    // One user's mail failure must not stop the rest of the batch.
                    try {
                        Notification::send($user, new WeeklySummaryDigest($summary));
                        $sent++;
                    } catch (\Throwable $e) {
                        Log::warning("Weekly summary digest failed for user {$user->id}: ".$e->getMessage());
                    }
                }
            });

        $this->info("Queued weekly summary digest for {$sent} user(s).");

        return self::SUCCESS;
    }
}
