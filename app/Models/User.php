<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['name','email','password','phone','gender','dob', 'xp'];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'xp' => 'integer',
            'streak_updated_for_date' => 'date',      // or 'immutable_date'

        ];
    }
    public function lobbies(): BelongsToMany
    {
        return $this->belongsToMany(Lobby::class, 'lobby_members')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    /** Lobbies this user is hosting. */
    public function hostedLobbies(): HasMany
    {
        return $this->hasMany(Lobby::class, 'host_id');
    }
    public function partner(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'partners', 'user_a_id', 'user_b_id')
            ->withPivot('status', 'started_at', 'ended_at')
            ->wherePivot('status', 'active')
            ->withTimestamps();
    }
}
