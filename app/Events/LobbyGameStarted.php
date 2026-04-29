<?php

namespace App\Events;

use App\Models\LobbyGameSession;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class LobbyGameStarted implements ShouldBroadcastNow
{
    public function __construct(public LobbyGameSession $session, public string $code) {}

    public function broadcastOn()
    {
        return new PresenceChannel("lobby.{$this->code}");
    }

    // ADDED: frontend listens .listen(".LobbyGameStarted", ...)
    public function broadcastAs()
    {
        return 'LobbyGameStarted';
    }

    public function broadcastWith()
    {
        return [
            'sessionId'  => $this->session->id,
            'kind'       => $this->session->kind,
            'settings'   => $this->session->settings,
            'started_by' => $this->session->started_by,
            // Include full session so frontend can set activeGame directly
            'session'    => [
                'id'          => $this->session->id,
                'kind'        => $this->session->kind,
                'settings'    => $this->session->settings,
                'started_by'  => $this->session->started_by,
                'status'      => 'active',
                'started_at'  => $this->session->created_at->toISOString(),
            ],
        ];
    }
}