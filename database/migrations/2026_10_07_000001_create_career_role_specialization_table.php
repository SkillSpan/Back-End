<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_role_specialization', function (Blueprint $table) {
            $table->id();
            $table->foreignId('specialization_id')->constrained('specializations')->cascadeOnDelete();
            $table->foreignId('career_role_id')->constrained('career_roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['specialization_id', 'career_role_id'], 'career_role_specialization_unique');

            $table->index('career_role_id', 'career_role_specialization_career_role_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_role_specialization');
    }
};
