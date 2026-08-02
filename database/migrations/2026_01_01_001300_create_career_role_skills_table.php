<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_role_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->decimal('required_level', 3, 2)->default(0);
            $table->decimal('importance_weight', 4, 3)->default(0);
            $table->boolean('is_critical')->default(false);
            $table->foreignId('prerequisite_skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->timestamps();
            $table->unique(['career_role_id','skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_role_skills');
    }
};
