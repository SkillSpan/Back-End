<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_action_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_action_id')->constrained('roadmap_actions')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['roadmap_action_id', 'skill_id'], 'rap_unique_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_action_prerequisites');
    }
};
