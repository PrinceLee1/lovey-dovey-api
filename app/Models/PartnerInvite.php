<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerInvite extends Model
{
    protected $fillable = ['code','inviter_id','invitee_id','status','expires_at'];
    protected $casts = ['expires_at' => 'datetime'];
    public function inviter(){ return $this->belongsTo(User::class, 'inviter_id'); }
    public function invitee(){ return $this->belongsTo(User::class, 'invitee_id'); }
}
