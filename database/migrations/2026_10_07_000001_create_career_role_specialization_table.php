<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Many-to-many link between specializations and career roles.
 *
 * A career role is not owned by exactly one specialization: "Backend
 * Developer", for example, is relevant to Computer Science, Software
 * Engineering and Computer Engineering alike. Modelling that as a pivot
 * (rather than a `career_roles.specialization_id` column) keeps the
 * relationship additive and lets one role surface under several
 * specializations without duplication.
 *
 * The pivot mirrors the existing `career_role_skills` table: surrogate
 * id, cascade deletes on both sides, and a composite unique constraint so
 * the same (specialization, career role) pair can never be inserted twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_role_specialization', function (Blueprint $table) {
            $table->id();
            $table->foreignId('specialization_id')->constrained('specializations')->cascadeOnDelete();
            $table->foreignId('career_role_id')->constrained('career_roles')->cascadeOnDelete();
            $table->timestamps();

            // Prevents duplicate pairs; also serves as the FK index for
            // specialization_id (leftmost column).
            $table->unique(['specialization_id', 'career_role_id'], 'career_role_specialization_unique');

            // Independent index for the reverse lookup (career role -> its
            // specializations), which the composite unique cannot serve.
            $table->index('career_role_id', 'career_role_specialization_career_role_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_role_specialization');
    }
};
