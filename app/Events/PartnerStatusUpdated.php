<?php
namespace App\Events;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class PartnerStatusUpdated implements ShouldBroadcastNow {
    public function __construct(public int $userId, public array $payload) {}
    public function broadcastOn(){ return new PrivateChannel("private-user.{$this->userId}"); }
    public function broadcastWith(){ return $this->payload; } // e.g. ['status'=>'pending_unpair','by'=>['id'=>..,'name'=>..]]
}