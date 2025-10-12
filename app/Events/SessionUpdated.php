<?php
namespace App\Events;

use App\Models\GameSession;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
class SessionUpdated implements ShouldBroadcastNow
{
    public function __construct(public GameSession $session, public array $payload = []) {}

    public function broadcastOn() { return [new PresenceChannel('couple-session.'.$this->session->code)]; }
    public function broadcastAs() { return 'session.updated'; }
    public function broadcastWith() {
        // keep payload minimal
        return [
            'code'   => $this->session->code,
            'round'  => $this->session->round,
            'turn'   => $this->session->turn_user_id,
            'state'  => $this->payload['state'] ?? null,
            'status' => $this->session->status,
        ];
    }
}