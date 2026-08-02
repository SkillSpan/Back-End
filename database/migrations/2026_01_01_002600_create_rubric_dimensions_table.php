<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubric_dimensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained()->cascadeOnDelete();
            $table->enum('name', ['quality','commitment','communication','collaboration','creativity','problem_solving','delivery']);
            $table->decimal('weight', 4, 3)->default(0);
            $table->foreignId('skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rubric_dimensions');
    }
};
