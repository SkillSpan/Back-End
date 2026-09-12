<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-INT-01 — per-action intelligence metadata for roadmap_actions.
 * Prerequisites are stored relationally (skill FK) rather than as free
 * text; explanation carries the FastAPI-provided reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmap_actions', function (Blueprint $table) {
            $table->text('objective')->nullable()->after('description');
            $table->foreignId('prerequisite_skill_id')
                ->nullable()
                ->after('target_skill_id')
                ->constrained('skills')
                ->nullOnDelete();
            $table->text('explanation')->nullable()->after('completion_criteria');
            $table->decimal('estimated_duration_hours', 6, 2)->nullable()->after('estimated_hours');
            $table->unsignedInteger('fastapi_order')->nullable()->after('order_index');
        });
    }

    public function down(): void
    {
        Schema::table('roadmap_actions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prerequisite_skill_id');
            $table->dropColumn([
                'objective',
                'explanation',
                'estimated_duration_hours',
                'fastapi_order',
            ]);
        });
    }
};
