<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class PartnerStatusUpdated implements ShouldBroadcastNow
{
    public function __construct(public int $userId, public array $payload) {}

    public function broadcastOn()
    {
        // FIXED: was PrivateChannel("private-user.{id}") — double prefix
        return new PrivateChannel("user.{$this->userId}");
    }

    public function broadcastWith()
    {
        return $this->payload;
    }
}