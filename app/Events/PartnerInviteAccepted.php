<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class PartnerInviteAccepted implements ShouldBroadcastNow
{
    public function __construct(public int $inviterId, public array $partner) {}

    public function broadcastOn()
    {
        //  FIXED: was PrivateChannel("private-user.{id}") which made Pusher
        // send "private-private-user.{id}" — double prefix, never matched channels.php
        // PrivateChannel() adds "private-" automatically, so just pass "user.{id}"
        return new PrivateChannel("user.{$this->inviterId}");
    }

    public function broadcastWith()
    {
        return ['partner' => $this->partner];
    }
}