<?php

namespace App\Jobs;

use App\Events\UserPresenceUpdated;
use App\Models\UserPresence;
use App\Support\Broadcasting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateUserPresence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Sweeps stale presence rows to "offline" — a user whose tab crashed or
    // lost connection never gets a chance to send a final "offline" update,
    // so this is what actually clears them for their friends.
    public function handle(): void
    {
        UserPresence::where('status', '!=', 'offline')
            ->where('last_seen_at', '<', now()->subMinutes(2))
            ->each(function (UserPresence $presence) {
                $presence->update(['status' => 'offline', 'updated_at' => now()]);
                Broadcasting::fire(new UserPresenceUpdated($presence->user_id, 'offline', $presence->current_lobby_id));
            });
    }
}
