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
        Schema::create('game_invites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $t->foreignId('lobby_id')->nullable()->constrained('lobbies')->nullOnDelete();
            $t->enum('status', ['pending', 'accepted', 'declined', 'expired'])->default('pending');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void {
        Schema::dropIfExists('game_invites');
    }
};
