<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FriendActivity extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_id', 'activity_type', 'metadata', 'created_at'];
    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
