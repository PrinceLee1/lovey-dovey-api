<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->unique(); // intimate_commands
            $table->string('title');
            $table->string('category'); // Erotic
            $table->text('description');
            $table->integer('players');
            $table->integer('duration');
            $table->string('difficulty');
            $table->boolean('partner_required')->default(false);
            $table->timestamps();
        });
        Schema::create('game_prompts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('game_id')->constrained();
            $t->enum('target', ['self', 'partner']); 
            $t->text('prompt');
            $t->integer('level')->default(1); // intensity
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
        Schema::dropIfExists('game_prompts');
    }
};
