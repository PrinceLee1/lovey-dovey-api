<?php
namespace App\Events;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use App\Models\GameSession;
class CoupleSessionUpdated implements ShouldBroadcastNow {
  public function __construct(public GameSession $session) {}
  public function broadcastOn() { return new PresenceChannel('couple-session.'.$this->session->code); }
  public function broadcastAs() { return 'session.updated'; }
  public function broadcastWith() {
    return [
      'turnUserId'=>$this->session->turn_user_id,
      'round'=>$this->session->round,
      'state'=>$this->session->state,
      'status'=>$this->session->status,
      'serverTime'=>now()->toISOString(),
    ];
  }
}