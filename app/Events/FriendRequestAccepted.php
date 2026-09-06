<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class FriendRequestAccepted implements ShouldBroadcastNow
{
    public function __construct(public int $requesterId, public array $payload) {}

    public function broadcastOn()
    {
        return new PrivateChannel("user.{$this->requesterId}");
    }

    public function broadcastAs()
    {
        return 'FriendRequestAccepted';
    }

    public function broadcastWith()
    {
        return $this->payload;
    }
}
