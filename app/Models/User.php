<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
    protected $fillable = ['name','email','password','phone','gender','dob', 'xp', 'streak','streak_updated_for_date','is_admin','status','deactivated_at','is_plus','stripe_subscription_id','plus_expires_at','trial_ends_at','email_news','email_reminders','weekly_summary','private_profile'];


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
            'deactivated_at' => 'datetime',
            'email_news' => 'boolean',
            'email_reminders' => 'boolean',
            'weekly_summary' => 'boolean',
            'private_profile' => 'boolean',
            // Uncast, these serialize as raw 0/1 in JSON — several frontend
            // call sites do `{user?.is_admin && <Jsx/>}`, and `0 && <Jsx/>`
            // in JSX renders the literal text "0" instead of nothing (React
            // only skips false/null/undefined, not falsy numbers). Casting
            // to boolean here fixes it at the source for every consumer.
            'is_admin' => 'boolean',
            'is_plus' => 'boolean',
            'is_active' => 'boolean',
            'plus_expires_at' => 'datetime',
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * Whether the user currently has Plus access, either because they're a
     * paying subscriber (the raw is_plus column) or their free trial hasn't
     * ended yet. The raw column stays untouched by the trial — admin revenue
     * stats and `User::where('is_plus', true)` queries only ever count real
     * subscribers — this accessor only affects reads of $user->is_plus on a
     * loaded model (which is how every controller/JSON response gets it).
     */
    protected function isPlus(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => (bool) $value || ($this->trial_ends_at !== null && $this->trial_ends_at->isFuture()),
        );
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
    /**
     * The user's current active partner, resolved symmetrically across
     * partners.user_a_id/user_b_id. A plain belongsToMany can't express an
     * OR across two foreign key columns — which is what the old `partner()`
     * relation here tried to do, and got wrong: it only matched rows where
     * this user was user_a_id, so it silently returned empty for whichever
     * user in a pair happened to be user_b_id (partners.accept() always
     * stores user_a_id/user_b_id as min(id)/max(id), so this affected
     * roughly half of all paired users).
     *
     * Not an Eloquent relation, so it can't be used with with()/load() —
     * call it directly.
     */
    public function activePartner(): ?self
    {
        $link = Partner::where('status', 'active')
            ->where(fn ($q) => $q->where('user_a_id', $this->id)->orWhere('user_b_id', $this->id))
            ->first();

        if (! $link) {
            return null;
        }

        $partnerId = $link->user_a_id === $this->id ? $link->user_b_id : $link->user_a_id;

        return self::find($partnerId);
    }
    public function scopeActive($q){ return $q->where('status','active'); }

}
