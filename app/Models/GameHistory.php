<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameHistory extends Model
{
    protected $table = 'game_histories';
    protected $fillable = [
        'user_id','game_id','game_title','kind','category',
        'duration_minutes','players','difficulty',
        'rounds','skipped','xp_earned','meta','played_at',
    ];

    protected $casts = [
        'meta'      => 'array',
        'played_at' => 'datetime',
    ];
}
