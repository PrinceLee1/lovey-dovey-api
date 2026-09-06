<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class FriendRequestReceived implements ShouldBroadcastNow
{
    public function __construct(public int $addresseeId, public array $payload) {}

    public function broadcastOn()
    {
        return new PrivateChannel("user.{$this->addresseeId}");
    }

    public function broadcastAs()
    {
        return 'FriendRequestReceived';
    }

    public function broadcastWith()
    {
        return $this->payload;
    }
}
