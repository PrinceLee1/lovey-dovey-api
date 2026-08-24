<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_news')->default(true)->after('avatar_url');
            $table->boolean('email_reminders')->default(true)->after('email_news');
            $table->boolean('weekly_summary')->default(true)->after('email_reminders');
            $table->boolean('private_profile')->default(false)->after('weekly_summary');
        });

        $this->broadenStatusEnum(['active', 'deactivated', 'deleted']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_news', 'email_reminders', 'weekly_summary', 'private_profile']);
        });

        DB::table('users')->where('status', 'deleted')->update(['status' => 'deactivated']);
        $this->broadenStatusEnum(['active', 'deactivated']);
    }

    /**
     * users.status is a native ENUM (MySQL) / CHECK-constrained column
     * (SQLite). Widening the allowed set needs a real type change, which
     * requires doctrine/dbal for Schema::table(...)->change() — not
     * installed here. Do it directly instead: raw ALTER on MySQL, and a
     * rebuild-via-temp-column on SQLite (no ALTER COLUMN support there).
     */
    private function broadenStatusEnum(array $values): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $list = collect($values)->map(fn ($v) => "'{$v}'")->implode(',');

        if ($driver === 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->string('status_tmp', 20)->default('active')->after('status');
            });
            DB::statement('UPDATE users SET status_tmp = status');
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('users_status_index');
                $table->dropColumn('status');
            });
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('status_tmp', 'status');
                $table->index('status');
            });
        } else {
            DB::statement("ALTER TABLE users MODIFY status ENUM({$list}) NOT NULL DEFAULT 'active'");
        }
    }
};
