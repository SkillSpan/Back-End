<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap v1 contract alignment — the FastAPI Roadmap returns the effort
 * (`estimated_hours`) and the calendar duration (`estimated_duration_weeks`)
 * as two DISTINCT values, so roadmap_actions stores them separately.
 *
 * The legacy `estimated_duration_hours` column is deliberately NOT
 * dropped: it is a different quantity (hours), it cannot be converted to
 * weeks correctly (weeks depend on the learner's weekly availability,
 * which is not recorded per historical action), and a destructive drop is
 * not required. It is simply no longer part of the Roadmap v1 contract —
 * see the deprecation note on the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmap_actions', function (Blueprint $table) {
            $table->decimal('estimated_duration_weeks', 6, 2)
                ->nullable()
                ->after('estimated_hours');
        });
    }

    public function down(): void
    {
        Schema::table('roadmap_actions', function (Blueprint $table) {
            $table->dropColumn('estimated_duration_weeks');
        });
    }
};
