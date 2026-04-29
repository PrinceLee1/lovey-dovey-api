<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class PartnerInviteRejected implements ShouldBroadcastNow
{
    public function __construct(public int $inviterId) {}

    public function broadcastOn()
    {
        // FIXED: was PrivateChannel("private-user.{id}") — double prefix
        return new PrivateChannel("user.{$this->inviterId}");
    }

    public function broadcastWith()
    {
        return ['rejected' => true];
    }
}