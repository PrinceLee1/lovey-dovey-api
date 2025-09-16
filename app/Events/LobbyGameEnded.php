<?php
namespace App\Events;
use App\Models\LobbyGameSession;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class LobbyGameEnded implements ShouldBroadcastNow {
  public function __construct(public LobbyGameSession $session, public string $code) {}
  public function broadcastOn(){ return new PresenceChannel("presence-lobby.{$this->code}"); }
  public function broadcastWith(){
    return [
      'sessionId'=>$this->session->id,
      'result'=>$this->session->result,
      'ended_at'=>optional($this->session->ended_at)->toISOString(),
    ];
  }
}