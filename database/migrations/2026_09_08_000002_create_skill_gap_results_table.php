<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-INT-01 — historical skill gap results.
 *
 * One row per (decision, skill): the validated FastAPI gap output.
 * Append-only — a new calculation creates a new decision with new gap
 * rows; old rows are never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_gap_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_snapshot_id')->constrained('decision_snapshots')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->decimal('current_level', 3, 2);
            $table->decimal('required_level', 3, 2);
            $table->decimal('gap', 3, 2);
            $table->decimal('match_score', 5, 2)->nullable();
            $table->decimal('importance_weight', 4, 3);
            $table->boolean('is_critical')->default(false);
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('status', 20)->default('gap');
            $table->text('explanation')->nullable();
            $table->timestamps();

            $table->unique(['decision_snapshot_id', 'skill_id']);
            $table->index(['decision_snapshot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_gap_results');
    }
};
