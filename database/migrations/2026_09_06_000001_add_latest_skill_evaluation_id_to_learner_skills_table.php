<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learner_skills', function (Blueprint $table) {
            $table->foreignId('latest_skill_evaluation_id')
                ->nullable()
                ->after('skill_id')
                ->constrained('skill_evaluations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('learner_skills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('latest_skill_evaluation_id');
        });
    }
};
