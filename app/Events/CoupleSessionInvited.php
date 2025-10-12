<?php
// app/Events/CoupleSessionInvited.php
namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class CoupleSessionInvited implements ShouldBroadcastNow
{
    public function __construct(
        public int $receiverUserId,
        public string $code,
        public string $kind,
        public string $inviterName,
        public ?int $pairId = null
    ) {}

    public function broadcastOn() { return [new PrivateChannel('user.'.$this->receiverUserId)]; }
    public function broadcastAs() { return 'couple.session.invited'; }
    public function broadcastWith() {
        return ['code'=>$this->code,'kind'=>$this->kind,'from'=>$this->inviterName,'pairId'=>$this->pairId];
    }
}
