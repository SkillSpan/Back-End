<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * NOTE: the `users` table itself is created by
     * 2026_01_01_000000_create_users_table.php (custom schema with
     * phone/locale/status/etc.). This migration only creates the
     * remaining default Laravel tables to avoid a duplicate
     * "Schema::create('users', ...)" call.
     *
     * NOTE (deployment fix): both tables are created only when missing.
     *
     * `password_reset_tokens` is created by three different migrations —
     * this one, 2026_01_01_000300_create_role_permission_table.php and
     * 2026_08_13_190000_rebuild_password_reset_tokens_table.php — so on a
     * database that already holds the table while this migration is still
     * unrecorded in `migrations`, the unguarded Schema::create() aborted
     * `php artisan migrate --force` with
     *   SQLSTATE[42S01]: Base table or view already exists: 1050
     *   Table 'password_reset_tokens' already exists
     * and blocked every Render deployment. `sessions` is framework-owned
     * and can pre-exist from an earlier deployment of the same database.
     *
     * The guard is a no-op on a fresh database (the tables are absent, so
     * they are created exactly as before). It never drops a table and
     * never touches a row.
     */
    public function up(): void
    {
        if (! Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
