<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal notes a company keeps about a candidate.
 *
 * Scoped to the organization, NOT to the author: the point of a note is that a
 * colleague can read it before the next interview. `author_id` records who
 * wrote it, which is what the UI shows beside the text.
 *
 * The note is the company's own record. It is never returned to the student and
 * never enters a talent payload — only `GET /talent/candidates/{id}/notes`
 * reads this table, and that route is company-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['organization_id', 'student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_notes');
    }
};
