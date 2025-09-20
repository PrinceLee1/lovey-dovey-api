<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    protected $fillable = ['user_a_id','user_b_id','status','unpair_requested_by','started_at','ended_at'];
    protected $casts = ['started_at'=>'datetime','ended_at'=>'datetime'];
    public function a(){ return $this->belongsTo(User::class, 'user_a_id'); }
    public function b(){ return $this->belongsTo(User::class, 'user_b_id'); }
}
