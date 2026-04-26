<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GamePrompts extends Model
{
    protected $fillable = ['game_id', 'target', 'prompt', 'level'];

    public function game()
    {
        return $this->belongsTo(Games::class, 'game_id');
    }
}
