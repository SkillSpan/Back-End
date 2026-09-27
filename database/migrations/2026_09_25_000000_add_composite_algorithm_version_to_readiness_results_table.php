<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-001 §3.2 / §4 — record the STRUCTURE version of the Composite
 * Readiness calculation alongside its numeric configuration version.
 *
 * `configuration_version` (config-v{n}) pins the tunable numbers; this new
 * column pins the aggregation policy itself (which components exist, how
 * unavailable ones are excluded and their weights redistributed, the
 * Critical Skill rule, the Banding rule). Without it a historical score
 * cannot be replayed once the structure moves on, and the version cannot be
 * back-filled later.
 *
 * Purely additive and idempotent: existing columns and every historical row
 * stay untouched, and re-running is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('readiness_results', function (Blueprint $table) {
            if (! Schema::hasColumn('readiness_results', 'composite_algorithm_version')) {
                $table->string('composite_algorithm_version')
                    ->nullable()
                    ->after('configuration_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('readiness_results', function (Blueprint $table) {
            if (Schema::hasColumn('readiness_results', 'composite_algorithm_version')) {
                $table->dropColumn('composite_algorithm_version');
            }
        });
    }
};
