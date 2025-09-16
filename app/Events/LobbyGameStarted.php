<?php 
namespace App\Events;
use App\Models\LobbyGameSession;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class LobbyGameStarted implements ShouldBroadcastNow {
  public function __construct(public LobbyGameSession $session, public string $code) {}
  public function broadcastOn(){ return new PresenceChannel("presence-lobby.{$this->code}"); }
  public function broadcastWith(){
    return [
      'sessionId'=>$this->session->id,
      'kind'=>$this->session->kind,
      'settings'=>$this->session->settings,
      'started_by'=>$this->session->started_by,
    ];
  }
}