<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class GameInviteReceived implements ShouldBroadcastNow
{
    public function __construct(public int $receiverId, public array $payload) {}

    public function broadcastOn()
    {
        return new PrivateChannel("invites.{$this->receiverId}");
    }

    public function broadcastAs()
    {
        return 'GameInviteReceived';
    }

    public function broadcastWith()
    {
        return $this->payload;
    }
}
