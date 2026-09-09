<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_role_skill_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_role_skill_id')->constrained('career_role_skills')->cascadeOnDelete();
            $table->foreignId('prerequisite_skill_id')->constrained('skills')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['career_role_skill_id', 'prerequisite_skill_id'], 'crsd_unique_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_role_skill_dependencies');
    }
};
