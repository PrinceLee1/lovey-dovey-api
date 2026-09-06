<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class FriendActivityLogged implements ShouldBroadcastNow
{
    public function __construct(public int $friendId, public array $payload) {}

    public function broadcastOn()
    {
        return new PrivateChannel("feed.{$this->friendId}");
    }

    public function broadcastAs()
    {
        return 'FriendActivityLogged';
    }

    public function broadcastWith()
    {
        return $this->payload;
    }
}
