<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->unique(
                ['student_profile_id', 'career_role_id', 'version'],
                'roadmaps_learner_role_version_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->dropUnique('roadmaps_learner_role_version_unique');
        });
    }
};
