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
        Schema::create('friendships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('addressee_id')->constrained('users')->cascadeOnDelete();
            $t->enum('status', ['pending', 'accepted', 'blocked'])->default('pending');
            $t->timestamps();

            $t->unique(['requester_id', 'addressee_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('friendships');
    }
};
