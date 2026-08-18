<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('readiness_results', function (Blueprint $table) {
            $table->enum('band', [
                'foundation_needed',
                'developing',
                'moderate_readiness',
                'near_ready',
                'highly_ready',
            ])->nullable()->change();
        });

        Schema::table('skill_evaluations', function (Blueprint $table) {
            $table->index(
                ['student_profile_id', 'skill_id', 'calculated_at'],
                'skill_evaluations_latest_lookup_idx'
            );
        });

        Schema::table('readiness_results', function (Blueprint $table) {
            $table->index(
                ['student_profile_id', 'career_role_id', 'calculated_at'],
                'readiness_results_latest_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('skill_evaluations', function (Blueprint $table) {
            $table->dropIndex('skill_evaluations_latest_lookup_idx');
        });

        Schema::table('readiness_results', function (Blueprint $table) {
            $table->dropIndex('readiness_results_latest_lookup_idx');
            $table->enum('band', [
                'foundation_needed',
                'developing',
                'moderate_readiness',
                'near_ready',
                'highly_ready',
            ])->change();
        });
    }
};
