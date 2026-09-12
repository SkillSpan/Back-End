<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Splits the previously-overloaded `algorithm_version` column:
     * - `algorithm_version` now holds the FastAPI skill-gap algorithm
     *   version returned in the response (e.g. "skill-gap-v1").
     * - `configuration_version` (new) holds the Laravel readiness
     *   formula/weights version (e.g. "readiness-v1").
     *
     * Existing rows keep whatever they already have in `algorithm_version`
     * (previously the Laravel formula version) and get a null
     * `configuration_version`, since we cannot retroactively know which
     * FastAPI algorithm version produced them.
     */
    public function up(): void
    {
        Schema::table('readiness_results', function (Blueprint $table) {
            $table->string('configuration_version')->nullable()->after('algorithm_version');
        });
    }

    public function down(): void
    {
        Schema::table('readiness_results', function (Blueprint $table) {
            $table->dropColumn('configuration_version');
        });
    }
};
