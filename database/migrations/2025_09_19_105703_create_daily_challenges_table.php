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
        Schema::create('daily_challenges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('partner_user_id')->nullable()->index();
            $t->date('for_date')->index();                 // UTC date boundary (adjust later per user tz)
            $t->enum('kind', ['solo','duo']);
            $t->string('title', 200);
            $t->json('payload');                           // {description, steps[], duration_minutes, difficulty}
            $t->enum('status', ['pending','completed'])->default('pending');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();

            $t->unique(['user_id','for_date']);           // one per user per day
        });
    }
    public function down(): void { Schema::dropIfExists('daily_challenges'); }

};
