<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class UserPresenceUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public int $userId,
        public string $status,
        public ?int $currentLobbyId = null,
    ) {}

    public function broadcastOn()
    {
        return new PrivateChannel("presence.{$this->userId}");
    }

    public function broadcastAs()
    {
        return 'UserPresenceUpdated';
    }

    public function broadcastWith()
    {
        return [
            'user_id' => $this->userId,
            'status' => $this->status,
            'current_lobby_id' => $this->currentLobbyId,
        ];
    }
}
