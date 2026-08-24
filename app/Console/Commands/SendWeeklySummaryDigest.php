<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\WeeklySummaryDigest;
use App\Support\WeeklySummaryService;
use Illuminate\Console\Command;
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

                    Notification::send($user, new WeeklySummaryDigest($summary));
                    $sent++;
                }
            });

        $this->info("Queued weekly summary digest for {$sent} user(s).");

        return self::SUCCESS;
    }
}
