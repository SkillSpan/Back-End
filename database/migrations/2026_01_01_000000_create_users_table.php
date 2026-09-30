<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns this migration is the only owner of. They are what makes the
     * custom `users` schema different from Laravel's default one, and no
     * other migration creates them, so a pre-existing `users` table that
     * lacks them is stale and must not be silently accepted.
     */
    private const OWNED_COLUMNS = ['phone', 'locale', 'status', 'deleted_at'];

    /**
     * `users` is created here, and 0001_01_01_000000_create_users_table.php
     * carries the same file name stem (its `users` creation was removed on
     * purpose). When this migration is still unrecorded on a database that
     * already has the table, the unguarded Schema::create() aborted
     * `php artisan migrate --force` with
     *   SQLSTATE[42S01]: Base table or view already exists: 1050
     *   Table 'users' already exists
     *
     * The table is therefore created only when missing. Nothing is dropped
     * and no row is touched. Unlike a bare skip, an existing table is
     * verified first: if it is missing any column this migration owns, the
     * migration fails loudly with an actionable message instead of leaving a
     * half-migrated schema behind for the application to trip over.
     */
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            $missing = array_values(array_filter(
                self::OWNED_COLUMNS,
                static fn (string $column): bool => ! Schema::hasColumn('users', $column),
            ));

            if ($missing !== []) {
                throw new RuntimeException(
                    'The `users` table already exists but is missing: '.implode(', ', $missing).'. '
                    .'It was created before this migration and cannot be rebuilt automatically '
                    .'(rebuilding would destroy production user data). Reconcile the `migrations` '
                    .'table against the live schema before deploying — see `php artisan db:migration-doctor`.'
                );
            }

            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->string('locale', 10)->default('en');
            $table->enum('status', ['active', 'suspended', 'pending', 'deleted'])->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
