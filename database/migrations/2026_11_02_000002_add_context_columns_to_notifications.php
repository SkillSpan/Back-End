<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-COMP-03 — the Database task list asks every notification to carry the
 * organization it belongs to and the entity it is about, not just a free-text
 * `link`.
 *
 * Until now a notification knew *who* it was for (`user_id`), *what kind* of
 * event it was (`category`) and *where* it pointed (`link`) — but not *which
 * organization* produced it and *which record* it refers to. That is fine for
 * the mentor/student traffic this table was built for, and it is what the
 * company dashboard needs once US-PRJ-01 starts emitting project events: a
 * notification list that can be filtered per organization, and a client that
 * can open the exact record instead of parsing a URL.
 *
 * All three columns are nullable: every row written before this migration is
 * still valid, and a notification that genuinely has no organization (a
 * learner-to-mentor message) stays valid too.
 *
 * Guarded with hasColumn() so a re-run — or a database where the columns were
 * added by hand — is a no-op instead of an error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('notifications', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('user_id');
            }

            if (! Schema::hasColumn('notifications', 'entity_type')) {
                $table->string('entity_type')->nullable()->after('link');
            }

            if (! Schema::hasColumn('notifications', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            }
        });

        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasIndex('notifications', 'notifications_organization_id_index')) {
                $table->index('organization_id', 'notifications_organization_id_index');
            }

            if (! Schema::hasIndex('notifications', 'notifications_entity_type_entity_id_index')) {
                $table->index(['entity_type', 'entity_id'], 'notifications_entity_type_entity_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasIndex('notifications', 'notifications_organization_id_index')) {
                $table->dropIndex('notifications_organization_id_index');
            }

            if (Schema::hasIndex('notifications', 'notifications_entity_type_entity_id_index')) {
                $table->dropIndex('notifications_entity_type_entity_id_index');
            }
        });

        Schema::table('notifications', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['organization_id', 'entity_type', 'entity_id'],
                fn (string $column) => Schema::hasColumn('notifications', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
