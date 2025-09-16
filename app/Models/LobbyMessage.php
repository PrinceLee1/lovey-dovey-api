<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LobbyMessage extends Model
{
    protected $fillable = ['lobby_id','user_id','body'];
    public function user(){ return $this->belongsTo(User::class); }
}
