<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-MATCH-02 — project roles.
 *
 * GAP DISCOVERED: the spec for US-MATCH-02 assumes "a project has defined
 * project roles" and asks for the existing project-role schema to be reused.
 * There is no such schema. The only role-ish columns that exist today are:
 *   - projects.role                  (a single free-text string, nullable)
 *   - project_team_members.project_role (free-text string, nullable)
 * Neither can express "several selectable roles per project", so the smallest
 * clean schema that satisfies the story is introduced here.
 *
 * Deliberately NO capacity column: the existing capacity source of truth is
 * the global projects.capacity, and US-MATCH-02 explicitly requires enforcing
 * the existing global capacity rather than inventing a second capacity system.
 *
 * Additive and idempotent, matching the convention used by
 * 2026_09_29_000001_add_project_matching_fields_to_recommendations_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_roles')) {
            return;
        }

        Schema::create('project_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            // Availability is a property of the role itself, NOT a capacity
            // counter — "still available" means "not deactivated by the owner".
            // Capacity remains projects.capacity (see the class docblock).
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // A project cannot define the same role title twice.
            $table->unique(['project_id', 'title'], 'project_roles_project_title_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_roles');
    }
};
