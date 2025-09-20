<?php

namespace App\Events;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class PartnerInviteAccepted implements ShouldBroadcastNow {
    public function __construct(public int $inviterId, public array $partner) {}
    public function broadcastOn(){ return new PrivateChannel("private-user.{$this->inviterId}"); }
    public function broadcastWith(){ return ['partner' => $this->partner]; }
}