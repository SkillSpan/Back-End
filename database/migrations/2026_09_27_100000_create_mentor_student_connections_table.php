<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mentor_student_connections')) {
            Schema::create('mentor_student_connections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mentor_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
                $table->enum('status', ['pending', 'active', 'disconnected', 'archived'])->default('pending');
                $table->enum('initiated_by', ['mentor', 'student', 'admin'])->default('mentor');
                $table->text('disconnected_reason')->nullable();
                $table->timestamp('disconnected_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                // Prevent duplicate connections for the same (mentor, student, project) triple.
                // project_id is nullable, so a NULL project means a general mentoring relationship.
                $table->unique(['mentor_id', 'student_id', 'project_id'], 'msc_mentor_student_project_unique');
                $table->index(['mentor_id', 'status']);
                $table->index(['student_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mentor_student_connections');
    }
};
