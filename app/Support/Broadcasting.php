<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class Broadcasting
{
    /**
     * Fire a broadcast event without letting a transport failure (bad
     * credentials, Pusher downtime, a network blip) break the request that
     * triggered it. Broadcasting is a best-effort real-time nicety here —
     * every screen that listens for these events also has a REST endpoint
     * it can fall back to (poll/refetch), so a missed push is degraded UX,
     * not a correctness problem. A hard failure of the parent action
     * (message send, lobby join, session action, etc.) would be worse.
     */
    public static function fire(object $event, bool $toOthers = false): void
    {
        try {
            // PendingBroadcast actually dispatches the event — making the
            // real Pusher network call for ShouldBroadcastNow events — from
            // its __destruct(), not from broadcast() itself. $pending must
            // be unset here, still inside the try block, or its destructor
            // fires after this method has already returned and a transport
            // failure would escape uncaught.
            $pending = broadcast($event);
            if ($toOthers) {
                $pending->toOthers();
            }
            unset($pending);
        } catch (\Throwable $e) {
            Log::warning('Broadcast failed for '.get_class($event).': '.$e->getMessage());
        }
    }
}
