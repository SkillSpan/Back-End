<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 11 follow-up — logical deduplication key for recommendations.
 *
 * Why a hashed key instead of a composite unique index:
 *
 * The logical identity of a project matching recommendation is
 * (user_id, candidate_type, candidate_id, project_version,
 * algorithm_version, configuration_version, + the result payload).
 * A composite unique index over those columns is not viable — three of them
 * are VARCHAR(255) and, with utf8mb4, 3 × 255 × 4 = 3060 bytes already, which
 * with the bigint columns exceeds MySQL's 3072-byte index limit. Hashing the
 * canonical key into a fixed 64-char column is engine-safe (MySQL and SQLite
 * both) and makes the uniqueness guarantee explicit rather than implicit.
 *
 * The column is nullable: it is only meaningful for recommendation types that
 * have a deterministic result identity. SQL treats NULLs as distinct in a
 * unique index, so non-project rows are unconstrained, which is correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            if (! Schema::hasColumn('recommendations', 'dedup_key')) {
                $table->string('dedup_key', 64)->nullable()->after('candidate_id');
            }
        });

        Schema::table('recommendations', function (Blueprint $table) {
            $table->unique('dedup_key', 'recommendations_dedup_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropUnique('recommendations_dedup_key_unique');
        });

        Schema::table('recommendations', function (Blueprint $table) {
            if (Schema::hasColumn('recommendations', 'dedup_key')) {
                $table->dropColumn('dedup_key');
            }
        });
    }
};
