<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            if (! Schema::hasColumn('recommendations', 'project_matching_snapshot_id')) {
                $table->foreignId('project_matching_snapshot_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('project_matching_snapshots')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('recommendations', 'configuration_version')) {
                $table->string('configuration_version')->nullable()
                    ->after('algorithm_version');
            }

            if (! Schema::hasColumn('recommendations', 'project_version')) {
                $table->unsignedInteger('project_version')->nullable()
                    ->after('configuration_version');
            }

            if (! Schema::hasColumn('recommendations', 'matching_state')) {
                $table->string('matching_state')->nullable()
                    ->after('eligibility_state');
            }

            if (! Schema::hasColumn('recommendations', 'weighted_contributions')) {
                $table->json('weighted_contributions')->nullable()
                    ->after('factors');
            }

            if (! Schema::hasColumn('recommendations', 'limiting_factors')) {
                $table->json('limiting_factors')->nullable()
                    ->after('reasons');
            }

            if (! Schema::hasColumn('recommendations', 'skill_results')) {
                $table->json('skill_results')->nullable()
                    ->after('weighted_contributions');
            }
        });

        Schema::table('recommendations', function (Blueprint $table) {
            $table->unique('project_matching_snapshot_id', 'recommendations_snapshot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            if (Schema::hasColumn('recommendations', 'project_matching_snapshot_id')) {
                $table->dropForeign(['project_matching_snapshot_id']);
            }
        });

        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropUnique('recommendations_snapshot_unique');
        });

        Schema::table('recommendations', function (Blueprint $table) {
            foreach (['skill_results', 'limiting_factors', 'weighted_contributions', 'matching_state'] as $column) {
                if (Schema::hasColumn('recommendations', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('recommendations', 'project_version')) {
                $table->dropColumn('project_version');
            }

            if (Schema::hasColumn('recommendations', 'configuration_version')) {
                $table->dropColumn('configuration_version');
            }

            if (Schema::hasColumn('recommendations', 'project_matching_snapshot_id')) {
                $table->dropColumn('project_matching_snapshot_id');
            }
        });
    }
};
