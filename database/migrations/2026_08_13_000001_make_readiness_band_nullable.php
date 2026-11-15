<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FK_SUPPORT_INDEXES = [
        'skill_evaluations' => 'skill_evaluations_student_profile_fk_idx',
        'readiness_results' => 'readiness_results_student_profile_fk_idx',
    ];

    public function up(): void
    {
        if (! Schema::hasIndex('skill_evaluations', 'skill_evaluations_latest_lookup_idx')) {
            Schema::table('skill_evaluations', function (Blueprint $table) {
                $table->index(
                    ['student_profile_id', 'skill_id', 'calculated_at'],
                    'skill_evaluations_latest_lookup_idx'
                );
            });
        }

        if (! Schema::hasIndex('readiness_results', 'readiness_results_latest_lookup_idx')) {
            Schema::table('readiness_results', function (Blueprint $table) {
                $table->index(
                    ['student_profile_id', 'career_role_id', 'calculated_at'],
                    'readiness_results_latest_lookup_idx'
                );
            });
        }

        foreach (self::FK_SUPPORT_INDEXES as $table => $index) {
            if (Schema::hasIndex($table, $index)) {
                Schema::table($table, function (Blueprint $blueprint) use ($index) {
                    $blueprint->dropIndex($index);
                });
            }
        }

        Schema::table('readiness_results', function (Blueprint $table) {
            $table->enum('band', [
                'foundation_needed',
                'developing',
                'moderate_readiness',
                'near_ready',
                'highly_ready',
            ])->nullable()->change();
        });
    }

    public function down(): void
    {
        foreach (self::FK_SUPPORT_INDEXES as $table => $index) {
            if (! Schema::hasIndex($table, $index)) {
                Schema::table($table, function (Blueprint $blueprint) use ($index) {
                    $blueprint->index('student_profile_id', $index);
                });
            }
        }

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
