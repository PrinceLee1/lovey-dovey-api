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
        Schema::create('user_presence', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $t->enum('status', ['online', 'idle', 'in_game', 'offline'])->default('offline');
            $t->foreignId('current_lobby_id')->nullable()->constrained('lobbies')->nullOnDelete();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void {
        Schema::dropIfExists('user_presence');
    }
};
