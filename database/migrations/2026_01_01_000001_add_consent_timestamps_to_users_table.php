<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUTH-08: record acceptance of required terms and privacy notices with timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'terms_accepted_at')) {
                $table->timestamp('terms_accepted_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('users', 'privacy_accepted_at')) {
                $table->timestamp('privacy_accepted_at')->nullable()->after('terms_accepted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Guarded individually: 2026_08_03_000001 manages the same two
            // columns and its down() runs BEFORE this one during a rollback
            // (later timestamp first), so they may already be gone. Without the
            // guard the rollback dies with
            //   SQLSTATE[42000]: 1091 Can't DROP COLUMN `terms_accepted_at`
            if (Schema::hasColumn('users', 'terms_accepted_at')) {
                $table->dropColumn('terms_accepted_at');
            }

            if (Schema::hasColumn('users', 'privacy_accepted_at')) {
                $table->dropColumn('privacy_accepted_at');
            }
        });
    }
};
