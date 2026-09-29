<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 11 — project matching recommendation persistence.
 *
 * The generic `recommendations` table (2026_01_01_004000) already stores one
 * row per learner-facing recommendation, keyed on user_id with a polymorphic
 * candidate. Project matching reuses it — type = 'project',
 * candidate_type = 'project', candidate_id = projects.id — rather than adding
 * a second, parallel recommendations table.
 *
 * This migration adds ONLY the fields the validated Task 10 response carries
 * that the table cannot express today, plus the link back to the matching
 * snapshot and a uniqueness guarantee.
 *
 * Additive and idempotent, matching the convention used by
 * 2026_09_28_000001_add_role_and_snapshot_to_baseline_assessments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            // Reproducibility: which immutable matching input set produced this
            // recommendation. Nullable because the table also holds non-project
            // recommendation types that have no matching snapshot.
            if (! Schema::hasColumn('recommendations', 'project_matching_snapshot_id')) {
                $table->foreignId('project_matching_snapshot_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('project_matching_snapshots')
                    ->nullOnDelete();
            }

            // Numbers version (ADR-001) alongside the existing algorithm_version.
            if (! Schema::hasColumn('recommendations', 'configuration_version')) {
                $table->string('configuration_version')->nullable()
                    ->after('algorithm_version');
            }

            // The project revision the recommendation was calculated against.
            if (! Schema::hasColumn('recommendations', 'project_version')) {
                $table->unsignedInteger('project_version')->nullable()
                    ->after('configuration_version');
            }

            // 'scored' | 'blocked' — part of the validated recommendation result.
            if (! Schema::hasColumn('recommendations', 'matching_state')) {
                $table->string('matching_state')->nullable()
                    ->after('eligibility_state');
            }

            // Per-factor contributions to the final score.
            if (! Schema::hasColumn('recommendations', 'weighted_contributions')) {
                $table->json('weighted_contributions')->nullable()
                    ->after('factors');
            }

            // Reasons the match is limited, when the service supplies them.
            if (! Schema::hasColumn('recommendations', 'limiting_factors')) {
                $table->json('limiting_factors')->nullable()
                    ->after('reasons');
            }

            // Per-skill breakdown from the validated result.
            if (! Schema::hasColumn('recommendations', 'skill_results')) {
                $table->json('skill_results')->nullable()
                    ->after('weighted_contributions');
            }
        });

        // Duplicate prevention: one matching result (one snapshot) yields at
        // most one stored recommendation. A unique index is the hard guarantee;
        // the service also short-circuits on an existing row.
        Schema::table('recommendations', function (Blueprint $table) {
            $table->unique('project_matching_snapshot_id', 'recommendations_snapshot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropUnique('recommendations_snapshot_unique');
        });

        Schema::table('recommendations', function (Blueprint $table) {
            foreach (['skill_results', 'limiting_factors', 'weighted_contributions', 'matching_state'] as $column) {
                if (Schema::hasColumn('recommendations', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('recommendations', 'project_version')) {
                $table->dropColumn('project_version');
            }

            if (Schema::hasColumn('recommendations', 'configuration_version')) {
                $table->dropColumn('configuration_version');
            }

            if (Schema::hasColumn('recommendations', 'project_matching_snapshot_id')) {
                $table->dropConstrainedForeignId('project_matching_snapshot_id');
            }
        });
    }
};
