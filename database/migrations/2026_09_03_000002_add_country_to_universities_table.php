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
        Schema::table('universities', function (Blueprint $table) {
            $table->dropUnique(['country_id', 'name']);
        });

        Schema::table('universities', function (Blueprint $table) {
            $table->unique(['name']);
        });

        Schema::table('universities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('country_id');
        });
    }
};
