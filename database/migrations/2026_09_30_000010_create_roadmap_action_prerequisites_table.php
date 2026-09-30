<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap v1 integration — a roadmap action may depend on MULTIPLE
 * prerequisite skills.
 *
 * The legacy single `roadmap_actions.prerequisite_skill_id` column is
 * kept for backward compatibility (it keeps holding the FIRST
 * prerequisite so existing readers keep working), but this table is the
 * source of truth for the full prerequisite set: it can never lose
 * prerequisites the way a single column does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_action_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_action_id')->constrained('roadmap_actions')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->timestamps();

            // A prerequisite may be listed only once per action.
            $table->unique(['roadmap_action_id', 'skill_id'], 'rap_unique_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_action_prerequisites');
    }
};
