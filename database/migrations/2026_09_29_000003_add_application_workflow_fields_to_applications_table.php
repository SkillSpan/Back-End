<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * US-MATCH-02 — application workflow fields.
 *
 * Adds only what the story requires and the current table cannot express.
 * The existing `applications` table already carries id, project_id,
 * applicant_id, status, decision_reason, decided_by, decided_at and
 * timestamps; none of those are duplicated here.
 *
 * WHY THE UNIQUE INDEX CHANGES
 * ----------------------------
 * The original `unique(project_id, applicant_id)` makes a withdrawn
 * application permanently block any future application to the same project,
 * which directly contradicts US-MATCH-02's rule that a learner must not have
 * more than one *active* application. It is replaced by
 * `unique(project_id, applicant_id, active_key)` where `active_key` is 1 for
 * active statuses and NULL for terminal ones — NULLs never collide in a unique
 * index on either MySQL or SQLite, so this enforces "at most one active
 * application" at the database level while still allowing a learner to
 * re-apply after withdrawing.
 *
 * `active_key` is maintained centrally by the Application model's saving hook,
 * never by callers, so the invariant cannot drift.
 *
 * WHY AN EXTRA INDEX IS CREATED FIRST (the deploy-breaking bit)
 * ------------------------------------------------------------
 * On MySQL/MariaDB an InnoDB foreign key must be backed by an index. The FK
 * `applications_project_id_foreign` never got an index of its own — the
 * composite unique below was doing that job, because it starts with
 * `project_id`. So `drop index applications_project_id_applicant_id_unique`
 * fails with:
 *
 *   SQLSTATE[HY000]: General error: 1553 Cannot drop index
 *   'applications_project_id_applicant_id_unique': needed in a foreign key constraint
 *
 * This cannot be reproduced on SQLite, which has no such coupling — hence the
 * failure only appeared on the Render deploy. The fix is to give the FK an
 * index of its own before dropping the composite one; MySQL then re-points the
 * FK and allows the drop. (The `applicant_id` FK is unaffected: MySQL created
 * `applications_applicant_id_foreign` for it at table-creation time.)
 *
 * Additive and idempotent, and safe to re-run on a database where an earlier
 * attempt already added the columns but failed on the index swap.
 */
return new class extends Migration
{
    /** Statuses that mean "this application is live" — mirrors Application::ACTIVE_STATUSES. */
    private const ACTIVE_STATUSES = ['submitted', 'shortlisted', 'accepted', 'waitlisted'];

    /** Plain index that exists purely to back the project_id foreign key. */
    private const PROJECT_FK_INDEX = 'applications_project_id_index';

    /** The legacy blanket unique this migration replaces. */
    private const LEGACY_UNIQUE = 'applications_project_id_applicant_id_unique';

    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            if (! Schema::hasColumn('applications', 'project_version')) {
                // The projects.version this application was submitted against.
                $table->unsignedInteger('project_version')->nullable()->after('project_id');
            }

            if (! Schema::hasColumn('applications', 'project_role_id')) {
                $table->foreignId('project_role_id')->nullable()->after('project_version')
                    ->constrained('project_roles')->nullOnDelete();
            }

            if (! Schema::hasColumn('applications', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('applications', 'withdrawn_at')) {
                $table->timestamp('withdrawn_at')->nullable()->after('submitted_at');
            }

            if (! Schema::hasColumn('applications', 'application_data')) {
                // Answers to the project's declared application questions.
                $table->json('application_data')->nullable()->after('withdrawn_at');
            }

            if (! Schema::hasColumn('applications', 'recommendation_id')) {
                // Set when the application originates from a stored recommendation.
                $table->foreignId('recommendation_id')->nullable()->after('application_data')
                    ->constrained('recommendations')->nullOnDelete();
            }

            // Which recommendation revision the learner was acting on. Reuses the
            // existing ADR-001 version vocabulary rather than inventing a
            // parallel `recommendation_version` concept.
            if (! Schema::hasColumn('applications', 'recommendation_algorithm_version')) {
                $table->string('recommendation_algorithm_version')->nullable()->after('recommendation_id');
            }

            if (! Schema::hasColumn('applications', 'recommendation_configuration_version')) {
                $table->string('recommendation_configuration_version')->nullable()
                    ->after('recommendation_algorithm_version');
            }

            if (! Schema::hasColumn('applications', 'idempotency_key')) {
                $table->string('idempotency_key', 191)->nullable()->after('recommendation_configuration_version');
            }

            if (! Schema::hasColumn('applications', 'request_fingerprint')) {
                // SHA-256 of the normalised request payload, so reusing a key
                // with a materially different body can be rejected.
                $table->string('request_fingerprint', 64)->nullable()->after('idempotency_key');
            }

            if (! Schema::hasColumn('applications', 'active_key')) {
                $table->unsignedTinyInteger('active_key')->nullable()->after('request_fingerprint');
            }
        });

        // 1. Give the project_id FK an index of its own, so the composite unique
        //    below is no longer load-bearing for the constraint (see docblock).
        if (! $this->hasIndex(self::PROJECT_FK_INDEX)) {
            Schema::table('applications', function (Blueprint $table) {
                $table->index('project_id', self::PROJECT_FK_INDEX);
            });
        }

        // 2. Backfill active_key for rows that predate the column. Without this
        //    every historical row keeps NULL, and a unique index treats NULL as
        //    "equal to nothing" — so the new constraint would silently not apply
        //    to any pre-existing application.
        DB::table('applications')
            ->whereNull('active_key')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->update(['active_key' => 1]);

        // 3. Swap the uniqueness. The drop only succeeds because of step 1.
        if ($this->hasIndex(self::LEGACY_UNIQUE)) {
            Schema::table('applications', function (Blueprint $table) {
                $table->dropUnique(self::LEGACY_UNIQUE);
            });
        }

        if (! $this->hasIndex('applications_active_unique')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->unique(['project_id', 'applicant_id', 'active_key'], 'applications_active_unique');
            });
        }

        if (! $this->hasIndex('applications_idempotency_unique')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->unique(['applicant_id', 'project_id', 'idempotency_key'], 'applications_idempotency_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['applications_active_unique', 'applications_idempotency_unique'] as $index) {
            if ($this->hasIndex($index)) {
                Schema::table('applications', function (Blueprint $table) use ($index) {
                    $table->dropUnique($index);
                });
            }
        }

        Schema::table('applications', function (Blueprint $table) {
            if (Schema::hasColumn('applications', 'project_role_id')) {
                $table->dropConstrainedForeignId('project_role_id');
            }

            if (Schema::hasColumn('applications', 'recommendation_id')) {
                $table->dropConstrainedForeignId('recommendation_id');
            }

            foreach ([
                'active_key',
                'request_fingerprint',
                'idempotency_key',
                'recommendation_configuration_version',
                'recommendation_algorithm_version',
                'application_data',
                'withdrawn_at',
                'submitted_at',
                'project_version',
            ] as $column) {
                if (Schema::hasColumn('applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // Restore the original composite unique BEFORE dropping the FK-supporting
        // index: the composite index starts with project_id, so once it is back
        // the plain index is redundant — but dropping it first would leave the
        // foreign key without any index (error 1553 again, in reverse).
        if (! $this->hasIndex(self::LEGACY_UNIQUE)) {
            Schema::table('applications', function (Blueprint $table) {
                $table->unique(['project_id', 'applicant_id']);
            });
        }

        if ($this->hasIndex(self::PROJECT_FK_INDEX)) {
            Schema::table('applications', function (Blueprint $table) {
                $table->dropIndex(self::PROJECT_FK_INDEX);
            });
        }
    }

    /**
     * Read the live index list rather than trusting a cached one — the list
     * changes several times inside up().
     */
    private function hasIndex(string $name): bool
    {
        return in_array(
            $name,
            collect(Schema::getIndexes('applications'))->pluck('name')->all(),
            true
        );
    }
};
