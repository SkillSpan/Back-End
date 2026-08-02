<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['project','roadmap_action','learning_resource','assessment','career_role']);
            $table->string('candidate_type');
            $table->unsignedBigInteger('candidate_id');
            $table->decimal('score', 6, 2)->nullable();
            $table->json('factors')->nullable();
            $table->text('reasons')->nullable();
            $table->string('algorithm_version')->nullable();
            $table->enum('eligibility_state', ['eligible','ineligible'])->default('eligible');
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->index(['candidate_type','candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
