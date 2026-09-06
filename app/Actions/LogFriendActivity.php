<?php

namespace App\Actions;

use App\Jobs\BroadcastFriendActivity;
use App\Models\FriendActivity;

class LogFriendActivity
{
    public static function log(int $userId, string $type, array $metadata = []): FriendActivity
    {
        $activity = FriendActivity::create([
            'actor_id' => $userId,
            'activity_type' => $type,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        // afterCommit() so a queue driver other than the default "sync" never
        // picks this up before the caller's own DB::transaction() (most
        // callers of ::log() are inside one) has actually committed.
        BroadcastFriendActivity::dispatch($activity->id)->afterCommit();

        return $activity;
    }
}
