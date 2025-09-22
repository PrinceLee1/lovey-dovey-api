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
            $t->boolean('is_admin')->default(false)->index();
            $t->enum('status', ['active','deactivated'])->default('active')->index();
            $t->timestamp('deactivated_at')->nullable();
            if (!Schema::hasColumn('users','deleted_at')) {
                $t->softDeletes();
            }
        });
    }
    public function down(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['is_admin','status','deactivated_at','deleted_at']);
        });
    }
};
