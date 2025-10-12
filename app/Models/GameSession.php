<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameSession extends Model
{
    protected $fillable = ['code','kind','created_by','partner_user_id','turn_user_id','state','status','started_at','ended_at'];
    protected $casts = [
        'state' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];
     public function getRouteKeyName(): string
    {
        return 'code';
    }
}
