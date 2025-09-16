<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
  public function up(): void {
    Schema::create('lobby_messages', function (Blueprint $t) {
      $t->id();
      $t->foreignId('lobby_id')->constrained('lobbies')->cascadeOnDelete();
      $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $t->text('body');
      $t->timestamps();
    });

    Schema::create('lobby_game_sessions', function (Blueprint $t) {
      $t->id();
      $t->foreignId('lobby_id')->constrained('lobbies')->cascadeOnDelete();
      $t->foreignId('started_by')->constrained('users')->cascadeOnDelete();
      $t->enum('kind', ['trivia','charades_ai']);
      $t->enum('status', ['active','ended'])->default('active');
      $t->json('settings')->nullable();   // e.g. {count:10, secondsPerQ:30}
      $t->json('result')->nullable();     // final summary (scores, xp, etc.)
      $t->timestamp('started_at')->useCurrent();
      $t->timestamp('ended_at')->nullable();
      $t->timestamps();
    });
  }
  public function down(): void {
    Schema::dropIfExists('lobby_messages');
    Schema::dropIfExists('lobby_game_sessions');
  }
};
