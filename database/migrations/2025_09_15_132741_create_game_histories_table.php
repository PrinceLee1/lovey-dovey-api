<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void {
        Schema::create('game_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('game_id', 50);
            $table->string('game_title', 200);
            $table->string('kind', 50);                 // truth_dare, trivia, etc.
            $table->string('category', 50);             // Romantic, Playful…
            $table->unsignedSmallInteger('duration_minutes')->default(0);
            $table->unsignedSmallInteger('players')->default(2);
            $table->string('difficulty', 20)->nullable();

            $table->unsignedSmallInteger('rounds')->default(0);
            $table->unsignedSmallInteger('skipped')->default(0);
            $table->unsignedMediumInteger('xp_earned')->default(0);

            $table->json('meta')->nullable();           // anything extra
            $table->timestamp('played_at')->useCurrent()->index();
            $table->timestamps();

            $table->index(['user_id', 'played_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('game_histories');
    }
};
