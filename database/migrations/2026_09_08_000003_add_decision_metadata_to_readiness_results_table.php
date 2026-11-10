<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
