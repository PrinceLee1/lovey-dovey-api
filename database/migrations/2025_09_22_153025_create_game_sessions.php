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
    Schema::create('game_sessions', function (Blueprint $t) {
      $t->id();
      $t->string('code', 12)->unique();
      $t->string('kind', 40);              // truth_dare, emoji_chat, etc
      $t->foreignId('created_by')->constrained('users');
      $t->foreignId('partner_user_id')->constrained('users'); // partner of creator
      $t->enum('status', ['waiting','active','ended','aborted'])->default('waiting');
      $t->unsignedBigInteger('turn_user_id')->nullable();     // whose turn
      $t->unsignedInteger('round')->default(1);
      $t->json('state')->nullable();       // minimal game state (server-auth)
      $t->timestamp('started_at')->nullable();
      $t->timestamp('finished_at')->nullable();
      $t->timestamps();
    });
  }
  public function down(): void { Schema::dropIfExists('game_sessions'); }
};
