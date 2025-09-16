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
        Schema::create('lobbies', function (Blueprint $t) {
            $t->id();                               // bigint id
            $t->uuid('uuid')->unique();             // public id (optional)
            $t->string('code', 10)->unique();       // short join code e.g. X7PQK9
            $t->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $t->string('name');
            $t->unsignedTinyInteger('max_players')->default(4);
            $t->unsignedInteger('entry_coins')->default(0);
            $t->enum('privacy', ['Public','Private'])->default('Public');
            $t->enum('status', ['open','in_progress','ended'])->default('open');
            $t->string('game_kind')->nullable();    // e.g. 'trivia', 'charades_ai'
            $t->json('rules')->nullable();          // arbitrary rule config
            $t->timestamp('start_at')->nullable();  // UTC
            $t->timestamps();
        });

        Schema::create('lobby_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lobby_id')->constrained('lobbies')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->enum('role', ['host','player'])->default('player');
            $t->timestamp('joined_at')->useCurrent();
            $t->unique(['lobby_id','user_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('lobby_members');
        Schema::dropIfExists('lobbies');
    }
};
