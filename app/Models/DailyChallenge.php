<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyChallenge extends Model
{
    protected $fillable = [
        'user_id','partner_user_id','for_date','kind','title','payload','status','completed_at'
    ];
    protected $casts = [
        'payload' => 'array',
        'for_date' => 'date',
        'completed_at' => 'datetime',
    ];
}
