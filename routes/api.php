<?php

use App\Http\Controllers\Admin\AdminMetricsController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\CoupleSessionController;
use App\Http\Controllers\DailyChallengeController;
use App\Http\Controllers\GameAiController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\GameHistoryController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\LobbyController;
use App\Http\Controllers\LobbyRealTimeController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\StreakController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me',     [AuthController::class, 'me']);
    Route::post('/logout',[AuthController::class, 'logout']);
    Route::post('/ai/truth-dare', [GameAiController::class, 'truthDare']);
    Route::get('/history',  [GameHistoryController::class, 'index']);
    Route::post('/history', [GameHistoryController::class, 'store']);
    Route::post('/ai/trivia', [GameAiController::class, 'trivia']);
    Route::post('/ai/charades', [GameAiController::class, 'charades']);
    Route::put('/user', [AuthController::class, 'updateUser']);
    Route::post('/user/avatar', [AuthController::class, 'uploadAvatar']);
    Route::post('/user/password', [AuthController::class, 'changePassword']);

    Route::get('/lobbies/public', [LobbyController::class, 'indexPublic']);
    Route::get('/lobbies/mine',   [LobbyController::class, 'my']);
    Route::post('/lobbies',       [LobbyController::class, 'store']);

    Route::get('/lobbies/{code}',         [LobbyController::class, 'showByCode']);
    Route::post('/lobbies/{code}/join',   [LobbyController::class, 'join']);
    Route::post('/lobbies/{code}/leave',  [LobbyController::class, 'leave']);
    Route::post('/lobbies/{code}/close',  [LobbyController::class, 'close']);
    Route::delete('/lobbies/{id}',     [LobbyController::class, 'destroy']);

    Route::get( '/lobbies/{code}/messages',            [LobbyRealTimeController::class,'messages']);
    Route::post('/lobbies/{code}/messages',            [LobbyRealtimeController::class,'postMessage']);

    Route::get( '/lobbies/{code}/sessions',            [LobbyRealtimeController::class,'sessions']);
    Route::post('/lobbies/{code}/games/start',         [LobbyRealtimeController::class,'startGame']);
    Route::post('/lobbies/{code}/games/{id}/update',   [LobbyRealtimeController::class,'pushUpdate']);
    Route::post('/lobbies/{code}/games/{id}/end',      [LobbyRealtimeController::class,'endGame']);
  
    Route::post('/partner/invite',        [PartnerController::class,'createInvite']);
    Route::get( '/partner/invites',       [PartnerController::class,'invites']);
    Route::get( '/partner/lookup/{code}', [PartnerController::class,'lookup']);
    Route::post('/partner/accept/{code}', [PartnerController::class,'accept']);
    Route::post('/partner/reject/{code}', [PartnerController::class,'reject']);
    Route::post('/partner/unpair/request',[PartnerController::class,'unpairRequest']);
    Route::post('/partner/unpair/confirm',[PartnerController::class,'unpairConfirm']);
    Route::get('/partner/status',         [PartnerController::class,'status']);
    Route::post('/partner/unpair/cancel', [PartnerController::class,'unpairCancel']);

    Route::get('/daily-challenge', [DailyChallengeController::class, 'show']);
    Route::post('/daily-challenge/complete', [DailyChallengeController::class, 'complete']);
    Route::get('/leaderboard', [LeaderboardController::class, 'index']);

    Route::get('/streaks', [StreakController::class, 'show']);
    Route::get('/me/progress', [ProgressController::class, 'show']);

    Route::post('/sessions', [CoupleSessionController::class,'create']);
    Route::post('/couple-sessions/start', [CoupleSessionController::class,'start']);

    Route::get('/sessions/{code}', [CoupleSessionController::class,'show']);
    Route::post('/sessions/{code}/action', [CoupleSessionController::class,'action']);
    Route::post('/broadcasting/auth', function (Illuminate\Http\Request $request) {
        if (! $request->user()) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        $result = Broadcast::auth($request);
        return response()->json($result);
    })->middleware('auth:sanctum');
    Route::get('/games', [GameController::class, 'index']);
    Route::get('/games/{game}', [GameController::class, 'show']);
    Route::post('/subscribe/checkout', [SubscriptionController::class, 'checkout']);
    Route::get('lobbies/{code}/members', [LobbyController::class, 'members']);
    Route::post('/lobbies/{code}/reactions', [LobbyController::class, 'sendReaction']);
    Route::post('/lobbies/{code}/games/{sessionId}/action', [LobbyRealTimeController::class, 'gameAction']);


});
    Route::get('/test-broadcast/{sessionId}', function ($sessionId) {
    broadcast(new \App\Events\LobbyGameUpdate((int)$sessionId, [
        'type' => 'state',
        'data' => ['phase' => 'TEST', 'message' => 'broadcast works!'],
    ]));
    return response()->json(['ok' => true]);
});
Route::middleware(['auth:sanctum','admin'])->prefix('admin')->group(function(){
    Route::get('/metrics', [AdminMetricsController::class, 'show']);
    Route::get('/users',   [AdminUserController::class, 'index']);
    Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus']);
    Route::delete('/users/{user}',       [AdminUserController::class, 'destroy']);
});
Route::post('/webhooks/stripe', [SubscriptionController::class, 'webhook']);   

