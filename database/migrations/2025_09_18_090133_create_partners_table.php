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
    Schema::create('partners', function (Blueprint $t) {
      $t->id();
      // store the pair as ordered (a_id < b_id) to keep it unique
      $t->foreignId('user_a_id')->constrained('users')->cascadeOnDelete();
      $t->foreignId('user_b_id')->constrained('users')->cascadeOnDelete();
      $t->enum('status', ['active','pending_unpair','ended'])->default('active');
      $t->foreignId('unpair_requested_by')->nullable()->constrained('users')->nullOnDelete();
      $t->timestamp('started_at')->useCurrent();
      $t->timestamp('ended_at')->nullable();
      $t->timestamps();

      $t->unique(['user_a_id','user_b_id']); // the pair itself is unique
      // We’ll also enforce “user can only be in one active pair” in application code (see controller)
    });
  }
  public function down(): void { Schema::dropIfExists('partners'); }
};
