<?php

namespace App\Events;

use App\Models\LobbyMessage;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class LobbyMessageCreated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(public LobbyMessage $message, public string $code) {}

    public function broadcastOn()
    {
        return new PresenceChannel("lobby.{$this->code}");
    }

    // ADDED: without broadcastAs(), Laravel uses the full class name
    // "App\Events\LobbyMessageCreated" as the event name, which the frontend
    // would need to listen for as ".App\\Events\\LobbyMessageCreated" — ugly.
    // With broadcastAs() the frontend listens: .listen(".LobbyMessageCreated", ...)
    public function broadcastAs()
    {
        return 'LobbyMessageCreated';
    }

    public function broadcastWith()
    {
        return [
            'id'         => $this->message->id,
            'user'       => [
                'id'   => $this->message->user_id,
                'name' => $this->message->user->name,
                'avatar' => $this->message->user->avatar_url ?? null,
            ],
            'body'       => $this->message->body,
            'created_at' => $this->message->created_at->toISOString(),
        ];
    }
}