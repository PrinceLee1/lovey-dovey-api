<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LobbyGameSession extends Model
{
    protected $fillable = ['lobby_id','started_by','kind','status','settings','result','started_at','ended_at'];
    protected $casts = ['settings'=>'array','result'=>'array','started_at'=>'datetime','ended_at'=>'datetime'];
    public function lobby(){ return $this->belongsTo(Lobby::class); }
}
