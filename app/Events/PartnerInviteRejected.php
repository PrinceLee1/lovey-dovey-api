<?php

namespace App\Events;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class PartnerInviteRejected implements ShouldBroadcastNow {
    public function __construct(public int $inviterId) {}
    public function broadcastOn(){ return new PrivateChannel("private-user.{$this->inviterId}"); }
}