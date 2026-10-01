<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap v1 contract alignment — FastAPI returns
 * `blocking_prerequisite_skill_ids` on every action: the skills CURRENTLY
 * blocking it. This is a DIFFERENT quantity from `prerequisite_skill_ids`
 * (the action's declared prerequisites), so it is stored separately rather
 * than merged into `roadmap_action_prerequisites` — folding it into that
 * table would silently pollute the existing `prerequisites()` relation and
 * corrupt the meaning of `prerequisite_skill_ids`.
 *
 * A nullable JSON column is the smallest safe change: no historical
 * migration is touched, no table is created, no existing prerequisite row
 * is read, moved or deleted, and `down()` simply drops the new column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmap_actions', function (Blueprint $table) {
            $table->json('blocking_prerequisite_skill_ids')
                ->nullable()
                ->after('estimated_duration_weeks');
        });
    }

    public function down(): void
    {
        Schema::table('roadmap_actions', function (Blueprint $table) {
            $table->dropColumn('blocking_prerequisite_skill_ids');
        });
    }
};
