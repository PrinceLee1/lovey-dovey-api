<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "No user should appear twice across both columns" can't be expressed as a
     * single declarative DB constraint (a unique index on user1_id/user2_id
     * doesn't stop a user showing up as user2_id in one row and user1_id in
     * another). Enforced at the application layer instead, same as the
     * existing Partner model's pairing rules.
     */
    public function up(): void {
        Schema::create('couple_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user1_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('user2_id')->constrained('users')->cascadeOnDelete();
            $t->string('couple_name')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['user1_id', 'user2_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('couple_profiles');
    }
};
