<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baseline_assessments', function (Blueprint $table) {
            $table->unique(
                ['student_profile_id', 'assessment_type', 'assessment_version', 'career_role_id'],
                'baseline_attempt_role_unique'
            );

            $table->dropUnique('baseline_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::table('baseline_assessments', function (Blueprint $table) {
            $table->unique(
                ['student_profile_id', 'assessment_type', 'assessment_version'],
                'baseline_attempt_unique'
            );

            $table->dropUnique('baseline_attempt_role_unique');
        });
    }
};
