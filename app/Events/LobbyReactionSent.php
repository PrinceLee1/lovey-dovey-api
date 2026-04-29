<?php

// ── 1. Create: app/Events/LobbyReactionSent.php ───────────────────────────

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class LobbyReactionSent implements ShouldBroadcastNow
{
    public function __construct(
        public string $emoji,
        public string $senderName,
        public string $code
    ) {}

    public function broadcastOn()
    {
        return new PresenceChannel("lobby.{$this->code}");
    }

    public function broadcastAs()
    {
        return 'LobbyReactionSent'; // frontend: .listen(".LobbyReactionSent", ...)
    }

    public function broadcastWith()
    {
        return [
            'emoji'  => $this->emoji,
            'sender' => $this->senderName,
        ];
    }
}




