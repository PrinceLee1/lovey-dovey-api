<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Games extends Model
{
    protected $fillable = [
        'kind',
        'title',
        'category',
        'description',
        'players',
        'duration',
        'difficulty',
        'partner_required',
    ];

    protected $casts = [
        'partner_required' => 'boolean',
        'players' => 'integer',
        'duration' => 'integer',
    ];

    public function prompts()
    {
        return $this->hasMany(GamePrompts::class, 'game_id');
    }
}
