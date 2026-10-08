<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-MATCH-DATA-03 prerequisite — Project → Career Role.
 *
 * BEFORE: `projects.role` is a free-text string with no referential integrity.
 * AFTER:  `projects.career_role_id` is a real foreign key into the existing
 *         `career_roles` table. `career_role_id` becomes the single source of
 *         truth; the legacy `role` column is kept as a derived projection for
 *         the frozen project-matching contract and is written from the career
 *         role title (see ProjectLifecycleService).
 *
 * SAFETY
 * ------
 * The column is added NULLABLE on purpose:
 *
 *   - Existing pilot/production rows predate the relationship and have no
 *     career role to backfill from, so a NOT NULL column with no default
 *     would either fail the ALTER or silently blank every row. A nullable
 *     column preserves every existing row untouched.
 *   - Presence of a career role is enforced at the API boundary
 *     (StoreProjectRequest requires it on create), which is where the new
 *     "a project must have a career role" rule belongs. The database stays
 *     permissive so the migration is non-destructive and reversible.
 *
 * ON DELETE: nullOnDelete(), matching the nullable-FK convention already used
 * by `organization_id` and `rubric_id` on this table. A career role is
 * normally retired (status = retired) rather than deleted; if one ever is
 * deleted, the projects survive with an unset role rather than being
 * cascaded away.
 *
 * No new table is created — `career_roles` already exists and is reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('projects', 'career_role_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('career_role_id')
                ->nullable()
                ->after('role')
                ->constrained('career_roles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('projects', 'career_role_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('career_role_id');
        });
    }
};
