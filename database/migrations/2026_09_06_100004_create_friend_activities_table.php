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
        Schema::create('friend_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $t->enum('activity_type', ['game_completed', 'xp_gained', 'streak_milestone', 'friend_added']);
            $t->json('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('friend_activities');
    }
};
