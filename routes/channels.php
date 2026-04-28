<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Lobby;

/*
|--------------------------------------------------------------------------
| Broadcast Channels — FIXED
|--------------------------------------------------------------------------
|
| FIXES:
|  1. presence-lobby.{code} now returns ['id', 'name'] array instead of
|     just `true` — Pusher REQUIRES this for presence channels to work.
|     Returning `true` makes private channels work but silently breaks
|     presence, which is why members always showed as 0.
|
|  2. Added a membership check — only users who have joined the lobby
|     can authenticate the presence channel (security + correctness).
|
|  3. Added private:user.{id} channel for couple session invites.
|
| HOW PRESENCE AUTH WORKS:
|  - Client calls /broadcasting/auth with socket_id + channel_name
|  - Laravel calls this closure with the authenticated $user
|  - Return FALSE   → 403, user not allowed
|  - Return TRUE    → allowed but Pusher gets no user info (BROKEN for presence)
|  - Return ARRAY   → allowed AND Pusher knows who this socket is (CORRECT)
|
*/

// ── Presence: Lobby room ──────────────────────────────────────────────────
// Returns user data so Pusher can track who is in the channel.
// The array is what .here() / .joining() / .leaving() receives on the frontend.
Broadcast::channel('presence-lobby.{code}', function ($user, string $code) {
    $lobby = Lobby::where('code', $code)->first();

    if (! $lobby) {
        return false; // lobby doesn't exist
    }

    // Check the user is actually a member (joined via /lobbies/{code}/join)
    // If you don't have a pivot table yet, remove the ->where() check and
    // just return the user array — tighten this up once members table exists.
    $isMember = $lobby->users()->where('user_id', $user->id)->exists()
             || $lobby->host_id === $user->id; // host is always allowed

    if (! $isMember) {
        return false;
    }

    // This array is what arrives in .here([...]) and .joining({...})
    // on the frontend. Add any fields you want visible to other members.
    return [
        'id'     => $user->id,
        'name'   => $user->name,
        'avatar' => $user->avatar_url ?? null,
    ];
});

// ── Private: per-user notifications (couple session invites, etc.) ────────
Broadcast::channel('user.{id}', function ($user, int $id) {
    return (int) $user->id === $id;
});

// ── Private: couple session ───────────────────────────────────────────────
Broadcast::channel('couple-session.{code}', function ($user, string $code) {
    // Allow if user is part of this session (add your own check here)
    return ['id' => $user->id, 'name' => $user->name];
});