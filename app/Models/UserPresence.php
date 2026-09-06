<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPresence extends Model
{
    protected $table = 'user_presence';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['user_id', 'status', 'current_lobby_id', 'last_seen_at', 'updated_at'];
    protected $casts = ['last_seen_at' => 'datetime', 'updated_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function currentLobby()
    {
        return $this->belongsTo(Lobby::class, 'current_lobby_id');
    }
}
