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
        Schema::table('users', function (Blueprint $t) {
            $t->string('timezone', 64)->default('UTC');
            $t->unsignedInteger('streak_current')->default(0);
            $t->unsignedInteger('streak_longest')->default(0);
            $t->date('streak_updated_for_date')->nullable()->index();
        });

        Schema::table('partners', function (Blueprint $t) {
            $t->unsignedInteger('couple_streak_current')->default(0);
            $t->unsignedInteger('couple_streak_longest')->default(0);
            $t->date('couple_streak_updated_for_date')->nullable()->index();
        });
    }

    public function down(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['timezone','streak_current','streak_longest','streak_updated_for_date']);
        });
        Schema::table('partners', function (Blueprint $t) {
            $t->dropColumn(['couple_streak_current','couple_streak_longest','couple_streak_updated_for_date']);
        });
    }
};
