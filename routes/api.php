<?php

use App\Http\Controllers\GameAiController;
use App\Http\Controllers\GameHistoryController;
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

});
