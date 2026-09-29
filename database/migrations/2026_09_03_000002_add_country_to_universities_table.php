<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * countries → universities relationship (countries task):
 * universities.country_id references countries.id (restrictOnDelete —
 * a country with universities must not be silently deleted; this is
 * reference data, not user-owned rows).
 *
 * The original UNIQUE(name) was global, which the global Hipolabs import
 * breaks: university names repeat across countries. Uniqueness becomes
 * country-scoped: UNIQUE(country_id, name).
 *
 * country_id stays nullable so the existing seeded rows survive the
 * transition; UniversitySeeder backfills them by matching Hipolabs data
 * (and leaves a row null only when no match exists).
 *
 * ## down() ordering
 *
 * `universities_country_id_name_unique` is the only index whose leftmost
 * column is `country_id`, and that column carries
 * `universities_country_id_foreign`. MySQL refuses to drop an index a
 * foreign key still needs:
 *
 *   SQLSTATE[HY000]: General error: 1553 Cannot drop index
 *   'universities_country_id_name_unique': needed in a foreign key constraint
 *
 * So the foreign key is dropped first, then the composite unique index,
 * then the column. Restoring the original global UNIQUE(name) can fail on a
 * database that already holds the same university name under two different
 * countries — those rows are exactly what this migration was written to
 * allow, and this migration will not delete them to force the index back on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('universities', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('name')
                ->constrained('countries')->restrictOnDelete();
        });

        Schema::table('universities', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });

        Schema::table('universities', function (Blueprint $table) {
            $table->unique(['country_id', 'name']);
        });
    }

    public function down(): void
    {
        // 1. Drop the foreign key FIRST — the composite unique index below is
        //    the only index MySQL can use for it (error 1553 otherwise).
        Schema::table('universities', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
        });

        // 2. The composite unique index is no longer needed by the key.
        Schema::table('universities', function (Blueprint $table) {
            $table->dropUnique(['country_id', 'name']);
        });

        // 3. Restore the original global uniqueness.
        Schema::table('universities', function (Blueprint $table) {
            $table->unique(['name']);
        });

        // 4. Finally remove the column.
        Schema::table('universities', function (Blueprint $table) {
            $table->dropColumn('country_id');
        });
    }
};
