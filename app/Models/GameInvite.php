<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameInvite extends Model
{
    public $timestamps = false;

    protected $fillable = ['sender_id', 'receiver_id', 'game_id', 'lobby_id', 'status', 'expires_at', 'created_at'];
    protected $casts = ['expires_at' => 'datetime', 'created_at' => 'datetime'];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function game()
    {
        return $this->belongsTo(Games::class, 'game_id');
    }

    public function lobby()
    {
        return $this->belongsTo(Lobby::class, 'lobby_id');
    }
}
