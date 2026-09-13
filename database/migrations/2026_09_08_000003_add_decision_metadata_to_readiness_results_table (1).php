<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-INT-01 — link readiness_results to their decision snapshot and
 * record the configuration version used for the calculation. Purely
 * additive: existing columns and every historical row stay untouched.
 *
 * FIX: made idempotent (checks each column/FK exists before adding it).
 * A previous deploy attempt already created `decision_snapshot_id` on the
 * production DB, which made this migration fail with
 * "Duplicate column name 'decision_snapshot_id'" when it tried to run
 * again. Guarding each addition means this migration is now safe to run
 * regardless of how much of it already landed on the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('readiness_results', function (Blueprint $table) {
            if (! Schema::hasColumn('readiness_results', 'decision_snapshot_id')) {
                $table->foreignId('decision_snapshot_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('decision_snapshots')
                    ->cascadeOnDelete();
            }

            if (! Schema::hasColumn('readiness_results', 'configuration_version')) {
                $table->string('configuration_version')
                    ->nullable()
                    ->after('algorithm_version');
            }

            if (! Schema::hasColumn('readiness_results', 'request_id')) {
                $table->string('request_id', 64)
                    ->nullable()
                    ->after('configuration_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('readiness_results', function (Blueprint $table) {
            if (Schema::hasColumn('readiness_results', 'decision_snapshot_id')) {
                $table->dropConstrainedForeignId('decision_snapshot_id');
            }

            $existing = array_filter(
                ['configuration_version', 'request_id'],
                fn ($column) => Schema::hasColumn('readiness_results', $column)
            );

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
