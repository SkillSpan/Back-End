<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap v1 contract alignment — the FastAPI RoadmapResponse carries the
 * roadmap-level totals (`estimated_total_hours`, `estimated_duration_weeks`)
 * and a `limitations` list. They belong to the roadmap, NOT to a single
 * action, and were previously dropped on the floor.
 *
 * The new columns are nullable because historical roadmaps predate this
 * contract and because `estimated_duration_weeks` may legitimately be null
 * (FastAPI returns null when the learner has no weekly availability).
 * No historical migration is touched and no data is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            // Roadmap-level total effort (distinct from an action's
            // estimated_hours): decimal, matching the project's numeric
            // convention for effort values.
            $table->decimal('estimated_total_hours', 8, 2)
                ->nullable()
                ->after('explanation');

            // Calendar duration in weeks — integer | null (Roadmap v1).
            $table->unsignedInteger('estimated_duration_weeks')
                ->nullable()
                ->after('estimated_total_hours');

            // Machine-readable limitation codes reported by FastAPI.
            $table->json('limitations')
                ->nullable()
                ->after('estimated_duration_weeks');
        });
    }

    public function down(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->dropColumn([
                'estimated_total_hours',
                'estimated_duration_weeks',
                'limitations',
            ]);
        });
    }
};
