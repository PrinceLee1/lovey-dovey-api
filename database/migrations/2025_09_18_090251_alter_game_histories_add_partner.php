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
    Schema::table('game_histories', function (Blueprint $t) {
      $t->foreignId('partner_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
    });
  }
  public function down(): void {
    Schema::table('game_histories', function (Blueprint $t) {
      $t->dropConstrainedForeignId('partner_user_id');
    });
  }
};
