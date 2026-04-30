<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
class Lobby extends Model
{
    protected $table = 'lobbies';
    protected $fillable = [
        'uuid','code','host_id','name','max_players','entry_coins',
        'privacy','status','game_kind','rules','start_at'
    ];
    protected $casts = ['rules'=>'array','start_at'=>'datetime'];

    protected static function booted() {
        static::creating(function (Lobby $l) {
            $l->uuid = $l->uuid ?: (string) Str::uuid();
            if (!$l->code) {
                do { $code = Str::upper(Str::random(6)); }
                while (self::where('code',$code)->exists());
                $l->code = $code;
            }
        });
    }

    public function host(): BelongsTo { return $this->belongsTo(User::class, 'host_id'); }

    public function members(): BelongsToMany {
        return $this->belongsToMany(User::class, 'lobby_members')
            ->withPivot('role','joined_at')->withTimestamps();
    }
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'lobby_user')
            ->withTimestamps();
    }
 
    public function messages(): HasMany
    {
        return $this->hasMany(LobbyMessage::class);
    }
 
    /**
     * Game sessions played in this lobby.
     * Used by withCount('sessions') in AdminController.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(LobbyGameSession::class, 'lobby_id');
    }

}
