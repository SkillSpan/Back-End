<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-INT-01 — link readiness_results to their decision snapshot and
 * record the configuration version used for the calculation. Purely
 * additive: existing columns and every historical row stay untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('readiness_results', function (Blueprint $table) {
            $table->foreignId('decision_snapshot_id')
                ->nullable()
                ->after('id')
                ->constrained('decision_snapshots')
                ->cascadeOnDelete();
            $table->string('configuration_version')
                ->nullable()
                ->after('algorithm_version');
            $table->string('request_id', 64)
                ->nullable()
                ->after('configuration_version');
        });
    }

    public function down(): void
    {
        Schema::table('readiness_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decision_snapshot_id');
            $table->dropColumn(['configuration_version', 'request_id']);
        });
    }
};
