<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap v1 integration — persist the action FastAPI nominates as the
 * learner's Next Best Action.
 *
 * Ownership: FastAPI returns `next_best_action_id` (its own action
 * identifier), Laravel resolves it to the persisted RoadmapAction of the
 * SAME roadmap and stores the local primary key here. The column is
 * nullable so every existing roadmap row (and any roadmap response that
 * omits the field) stays valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->foreignId('next_best_action_id')
                ->nullable()
                ->after('status')
                ->constrained('roadmap_actions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('next_best_action_id');
        });
    }
};
