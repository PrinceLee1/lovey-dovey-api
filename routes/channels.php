<?php

use App\Models\GameSession;
use App\Models\Partner;
use Illuminate\Support\Facades\Broadcast;

/**
 * Register the broadcasting auth endpoint for your API.
 * Use Sanctum (Bearer token) for auth.
 */
Broadcast::routes(['middleware' => ['auth:sanctum']]);

/**
 * Presence channel for lobby.
 * Return array to authorize; return false to deny.
 * Start permissive, tighten later if you want to gate by lobby membership.
 */
Broadcast::channel('presence-lobby.{code}', function ($user, string $code) {
    return ['id' => $user->id, 'name' => $user->name];
});

/** Public/read-only channel for game session updates */
Broadcast::channel('lobby-game.{sessionId}', function ($user, int $sessionId) {
    return ['id' => $user->id, 'name' => $user->name];
});
Broadcast::channel('private-user.{id}', function ($user, int $id) {
    return (int) $user->id === (int) $id;
});
Broadcast::channel('couple-session.{code}', function ($user, string $code) {
    $s = GameSession::where('code',$code)->first();
    if (!$s) return false;

    // must be the active pair
    $pair = Partner::where('status','active')
      ->where(function($q) use($s){ $q->where('user_a_id',$s->created_by)->orWhere('user_b_id',$s->created_by); })
      ->first();

    if (!$pair) return false;
    $ok = in_array($user->id, [$pair->user_a_id, $pair->user_b_id]);
    return $ok ? ['id'=>$user->id,'name'=>$user->name] : false;
});
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int)$user->id === (int)$id;   // must be logged-in
});
Broadcast::channel('couple-session.{code}', fn($user, $code) => ['id'=>$user->id,'name'=>$user->name]);

