<?php

use App\Models\GameSession;
use App\Models\Partner;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| NOTE: Remove the manual POST /broadcasting/auth from api.php if present.
| This Broadcast::routes() call already registers that endpoint under
| the auth:sanctum middleware, which is all your FE authorizer needs.
|--------------------------------------------------------------------------
*/
Broadcast::routes(['middleware' => ['auth:sanctum']]);

/*
|--------------------------------------------------------------------------
| Presence: Lobby
| Returns user data so all members can see who is in the lobby.
|--------------------------------------------------------------------------
*/
Broadcast::channel('presence-lobby.{code}', function ($user, string $code) {
    // Optionally validate the code exists in your DB here:
    // if (!Lobby::where('code', $code)->exists()) return false;
    return ['id' => $user->id, 'name' => $user->name];
});

/*
|--------------------------------------------------------------------------
| Public: Game Session updates
| Any authenticated user subscribed to the session can receive updates.
| Return true (not an array) — this is NOT a presence channel.
|--------------------------------------------------------------------------
*/
Broadcast::channel('lobby-game.{sessionId}', function ($user, int $sessionId) {
    return true;
});

/*
|--------------------------------------------------------------------------
| Private: Notifications for a specific user
| Canonical pattern — keep only one of private-user or user, not both.
|--------------------------------------------------------------------------
*/
Broadcast::channel('private-user.{id}', function ($user, int $id) {
    return (int) $user->id === (int) $id;
});

/*
|--------------------------------------------------------------------------
| Private: Per-user notification channel (alternative naming)
| Used by Laravel's built-in notification broadcasting.
| e.g. echo.private('user.1').notification(cb)
|--------------------------------------------------------------------------
*/
Broadcast::channel('user.{id}', function ($user, int $id) {
    return (int) $user->id === (int) $id;
});

/*
|--------------------------------------------------------------------------
| Presence: Couple Session
| Only the two users in the active partner pair for this session can join.
| IMPORTANT: Only registered once — the duplicate loose registration
| that previously overrode this secure check has been removed.
|--------------------------------------------------------------------------
*/
Broadcast::channel('couple-session.{code}', function ($user, string $code) {
    $session = GameSession::where('code', $code)->first();
    if (!$session) return false;

    $pair = Partner::where('status', 'active')
        ->where(function ($q) use ($session) {
            $q->where('user_a_id', $session->created_by)
              ->orWhere('user_b_id', $session->created_by);
        })
        ->first();

    if (!$pair) return false;

    $isInPair = in_array($user->id, [$pair->user_a_id, $pair->user_b_id]);

    return $isInPair
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});