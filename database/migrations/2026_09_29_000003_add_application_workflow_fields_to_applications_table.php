<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 * Additive and idempotent.
 */
return new class extends Migration
{
    /** Column => closure describing how to add it. */
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

        $indexes = collect(Schema::getIndexes('applications'))->pluck('name')->all();

        // Drop the old blanket uniqueness (see the class docblock).
        if (in_array('applications_project_id_applicant_id_unique', $indexes, true)) {
            Schema::table('applications', function (Blueprint $table) {
                $table->dropUnique('applications_project_id_applicant_id_unique');
            });
        }

        if (! in_array('applications_active_unique', $indexes, true)) {
            Schema::table('applications', function (Blueprint $table) {
                $table->unique(['project_id', 'applicant_id', 'active_key'], 'applications_active_unique');
            });
        }

        if (! in_array('applications_idempotency_unique', $indexes, true)) {
            Schema::table('applications', function (Blueprint $table) {
                $table->unique(['applicant_id', 'project_id', 'idempotency_key'], 'applications_idempotency_unique');
            });
        }
    }

    public function down(): void
    {
        $indexes = collect(Schema::getIndexes('applications'))->pluck('name')->all();

        foreach (['applications_active_unique', 'applications_idempotency_unique'] as $index) {
            if (in_array($index, $indexes, true)) {
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

        $indexes = collect(Schema::getIndexes('applications'))->pluck('name')->all();

        if (! in_array('applications_project_id_applicant_id_unique', $indexes, true)) {
            Schema::table('applications', function (Blueprint $table) {
                $table->unique(['project_id', 'applicant_id']);
            });
        }
    }
};
