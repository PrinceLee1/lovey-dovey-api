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

// ── Private: per-user presence updates (see UserPresenceUpdated) ─────────
// Frontend: echo.private(`presence.${id}`) → Pusher sends "private-presence.5"
// → Laravel strips "private-" → looks up "presence.5", matching this string.
// Any authenticated user can listen to a friend's presence.{id} channel —
// the frontend only ever subscribes to the caller's own accepted friends,
// and this only broadcasts a status string, so there's nothing to gate here
// beyond "must be logged in".
Broadcast::channel('presence.{id}', function ($user, int $id) {
    return true;
});

// ── Private: per-user game invites (see GameInviteReceived) ──────────────
Broadcast::channel('invites.{id}', function ($user, int $id) {
    return (int) $user->id === $id;
});

// ── Private: couple session ───────────────────────────────────────────────
Broadcast::channel('couple-session.{code}', function ($user, string $code) {
    $session = \App\Models\GameSession::where('code', $code)->first();

    if (! $session || ! in_array($user->id, [$session->created_by, $session->partner_user_id])) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});