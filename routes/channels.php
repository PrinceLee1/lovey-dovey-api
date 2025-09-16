<?php

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
