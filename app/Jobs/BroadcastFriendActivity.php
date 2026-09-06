<?php

namespace App\Jobs;

use App\Events\FriendActivityLogged;
use App\Models\FriendActivity;
use App\Models\Friendship;
use App\Support\Broadcasting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BroadcastFriendActivity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $activityId) {}

    public function handle(): void
    {
        $activity = FriendActivity::with('actor:id,name,avatar_url')->find($this->activityId);
        if (! $activity) {
            return;
        }

        $friendIds = Friendship::forUser($activity->actor_id)
            ->where('status', 'accepted')
            ->get()
            ->map(fn ($f) => (int) $f->requester_id === (int) $activity->actor_id ? $f->addressee_id : $f->requester_id);

        $payload = [
            'id' => $activity->id,
            'activity_type' => $activity->activity_type,
            'metadata' => $activity->metadata,
            'created_at' => $activity->created_at->toIso8601String(),
            'actor' => [
                'id' => $activity->actor->id,
                'name' => $activity->actor->name,
                'avatar_url' => $activity->actor->avatar_url,
            ],
        ];

        foreach ($friendIds as $friendId) {
            Broadcasting::fire(new FriendActivityLogged($friendId, $payload));
        }
    }
}
