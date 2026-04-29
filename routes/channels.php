<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Lobby;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| CRITICAL RULE:
| The string in Broadcast::channel('THIS_PART', ...) must be the channel
| name WITHOUT the type prefix that Laravel/Pusher strips automatically.
|
|   Frontend: echo.join(`lobby.${code}`)
|   Pusher sends to /broadcasting/auth: channel_name = "lobby.A55CTZ"
|   Laravel strips "presence-" → looks up channel: "lobby.A55CTZ"   ← must match this
|
|   Frontend: echo.private(`user.${id}`)
|   Pusher sends: "private-user.5"
|   Laravel strips "private-" → looks up: "user.5"   ← must match this
|
*/

// ── Presence: Lobby room ──────────────────────────────────────────────────
Broadcast::channel('lobby.{code}', function ($user, string $code) {
    $lobby = Lobby::where('code', $code)->first();

    if (! $lobby) {
        return false;
    }

    // For development: allow any authenticated user into any lobby.
    // In production: check $lobby->users()->where('user_id', $user->id)->exists()
    // OR $lobby->host_id === $user->id

    // MUST return an array for presence channels — returning `true` breaks presence
    return [
        'id'     => $user->id,
        'name'   => $user->name,
        'avatar' => $user->avatar_url ?? null,
    ];
});

// ── Private: per-user notifications (couple invites, etc.) ───────────────
Broadcast::channel('user.{id}', function ($user, int $id) {
    return (int) $user->id === $id;
});

// ── Private: couple session ───────────────────────────────────────────────
Broadcast::channel('couple-session.{code}', function ($user, string $code) {
    return ['id' => $user->id, 'name' => $user->name];
});