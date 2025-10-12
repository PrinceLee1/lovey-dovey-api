<?php
namespace App\Events;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use App\Models\GameSession;

class CoupleSessionCreated implements ShouldBroadcastNow {
  public function __construct(public GameSession $session) {}
  public function broadcastOn() { return new PresenceChannel('couple-session.'.$this->session->code); }
  public function broadcastAs() { return 'session.created'; }
  public function broadcastWith() {
    return [
      'code'=>$this->session->code,
      'kind'=>$this->session->kind,
      'turnUserId'=>$this->session->turn_user_id,
      'round'=>$this->session->round,
      'state'=>$this->session->state,
      'status'=>$this->session->status,
      'serverTime'=>now()->toISOString(),
    ];
  }
}