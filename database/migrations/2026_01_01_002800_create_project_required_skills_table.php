<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_required_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->decimal('minimum_level', 3, 2)->default(0);
            $table->boolean('is_critical_entry')->default(false);
            $table->timestamps();
            $table->unique(['project_id','skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_required_skills');
    }
};
