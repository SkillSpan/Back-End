<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives career roles an independent "is this role offered in the product?"
 * switch, separate from its review status.
 *
 * WHY A SECOND FLAG
 * -----------------
 * `status` answers "has this role definition been reviewed?" (draft,
 * approved, retired) — it is a content-governance state. The MVP needs a
 * different, orthogonal question: "should a learner be offered this role
 * right now?" A role can be perfectly approved and still be out of the
 * MVP scope (e.g. QA Engineer, IT Support Specialist). Retiring it would
 * lie about its review state and would also lose the ability to bring it
 * back with a single click, so a dedicated boolean is used instead.
 *
 * A role is offered to a learner only when BOTH hold:
 *   status = 'approved' AND is_active = true
 *
 * NON-DESTRUCTIVE BY DESIGN
 * -------------------------
 *  - The column is additive and defaults to TRUE, so every existing role
 *    stays visible the moment this migration runs — no behaviour change
 *    until a seeder or an administrator deliberately switches a role off.
 *  - Deactivating a role never deletes it, its skills, its questions or
 *    its pivot links; the row simply stops being offered. Re-activating is
 *    a single UPDATE.
 *  - `status` is not touched.
 *
 * The composite index backs the two columns the learner-facing list always
 * filters on together (status + is_active), so the MVP filter does not turn
 * into a table scan as the catalog grows.
 *
 * Reversible: `down()` drops the index and the column, restoring the exact
 * previous schema.
 */
return new class extends Migration
{
    private const INDEX = 'career_roles_status_is_active_index';

    public function up(): void
    {
        Schema::table('career_roles', function (Blueprint $table) {
            if (! Schema::hasColumn('career_roles', 'is_active')) {
                // default(true) is what keeps every existing row offered.
                $table->boolean('is_active')->default(true);
            }
        });

        Schema::table('career_roles', function (Blueprint $table) {
            if (! $this->hasIndex()) {
                $table->index(['status', 'is_active'], self::INDEX);
            }
        });
    }

    public function down(): void
    {
        Schema::table('career_roles', function (Blueprint $table) {
            if ($this->hasIndex()) {
                $table->dropIndex(self::INDEX);
            }
        });

        Schema::table('career_roles', function (Blueprint $table) {
            if (Schema::hasColumn('career_roles', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }

    /**
     * SQLite and MySQL both expose their indexes through the schema builder,
     * but only MySQL keeps a stable name list per table; checking by name
     * keeps `up()` re-runnable after a partially applied deploy without
     * throwing "duplicate key name".
     */
    private function hasIndex(): bool
    {
        foreach (Schema::getIndexes('career_roles') as $index) {
            if (($index['name'] ?? null) === self::INDEX) {
                return true;
            }
        }

        return false;
    }
};
