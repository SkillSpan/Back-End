<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Missing-component policy (agreed with Data Science): an unavailable
 * component (practical_experience / assessment_reliability) is excluded
 * and the remaining component weights are renormalized, instead of
 * blocking the whole calculation. `is_provisional` flags results
 * computed with at least one component excluded this way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('readiness_results', function (Blueprint $table) {
            if (! Schema::hasColumn('readiness_results', 'is_provisional')) {
                $table->boolean('is_provisional')
                    ->default(false)
                    ->after('critical_cap_applied');
            }
        });
    }

    public function down(): void
    {
        Schema::table('readiness_results', function (Blueprint $table) {
            if (Schema::hasColumn('readiness_results', 'is_provisional')) {
                $table->dropColumn('is_provisional');
            }
        });
    }
};
