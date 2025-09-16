<?php

namespace App\Events;
use App\Models\LobbyMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class LobbyMessageCreated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(public LobbyMessage $message, public string $code) {}

    public function broadcastOn() { return new PresenceChannel("presence-lobby.{$this->code}"); }

    public function broadcastWith() {
        return [
            'id' => $this->message->id,
            'user' => [
                'id' => $this->message->user_id,
                'name' => $this->message->user->name,
            ],
            'body' => $this->message->body,
            'created_at' => $this->message->created_at->toISOString(),
        ];
    }
}