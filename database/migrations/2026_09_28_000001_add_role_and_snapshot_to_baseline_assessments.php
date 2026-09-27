<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baseline_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('baseline_assessments', 'career_role_id')) {
                $table->foreignId('career_role_id')
                    ->nullable()
                    ->after('student_profile_id')
                    ->constrained('career_roles')
                    ->cascadeOnDelete();
            }

            if (! Schema::hasColumn('baseline_assessments', 'question_count')) {
                $table->unsignedInteger('question_count')->nullable()
                    ->after('assessment_version');
            }

            if (! Schema::hasColumn('baseline_assessments', 'skill_coverage')) {
                $table->json('skill_coverage')->nullable()
                    ->after('question_count');
            }

            if (! Schema::hasColumn('baseline_assessments', 'snapshot_metadata')) {
                $table->json('snapshot_metadata')->nullable()
                    ->after('skill_coverage');
            }
        });
    }

    public function down(): void
    {
        Schema::table('baseline_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('baseline_assessments', 'snapshot_metadata')) {
                $table->dropColumn('snapshot_metadata');
            }
            if (Schema::hasColumn('baseline_assessments', 'skill_coverage')) {
                $table->dropColumn('skill_coverage');
            }
            if (Schema::hasColumn('baseline_assessments', 'question_count')) {
                $table->dropColumn('question_count');
            }
            if (Schema::hasColumn('baseline_assessments', 'career_role_id')) {
                $table->dropConstrainedForeignId('career_role_id');
            }
        });
    }
};
