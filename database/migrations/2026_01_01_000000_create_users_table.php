<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OWNED_COLUMNS = ['phone', 'locale', 'status', 'deleted_at'];

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
