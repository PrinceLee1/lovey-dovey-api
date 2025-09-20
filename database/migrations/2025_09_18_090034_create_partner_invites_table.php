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
    Schema::create('partner_invites', function (Blueprint $t) {
      $t->id();
      $t->string('code', 10)->unique();      // e.g. X7PQK9AB
      $t->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
      $t->foreignId('invitee_id')->nullable()->constrained('users')->nullOnDelete();
      $t->enum('status', ['pending','accepted','rejected','canceled','expired'])->default('pending');
      $t->timestamp('expires_at')->nullable();
      $t->timestamps();
    });
  }
  public function down(): void { Schema::dropIfExists('partner_invites'); }
};
