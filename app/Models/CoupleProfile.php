<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoupleProfile extends Model
{
    public $timestamps = false;

    protected $fillable = ['user1_id', 'user2_id', 'couple_name', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function user1()
    {
        return $this->belongsTo(User::class, 'user1_id');
    }

    public function user2()
    {
        return $this->belongsTo(User::class, 'user2_id');
    }
}
