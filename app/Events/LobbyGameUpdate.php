<?php 
namespace App\Events;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class LobbyGameUpdate implements ShouldBroadcastNow {
  use InteractsWithSockets;
  public function __construct(public int $sessionId, public array $payload) {}
  public function broadcastOn(){ return new Channel("lobby-game.{$this->sessionId}"); }
  public function broadcastWith(){ return $this->payload; } // e.g. { type:'state', data:{...} }
  // Add this to LobbyGameUpdate.php
  public function broadcastAs(): string
  {
      return 'LobbyGameUpdate';
  }
}