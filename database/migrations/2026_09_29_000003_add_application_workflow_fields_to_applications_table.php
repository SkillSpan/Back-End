<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_STATUSES = ['submitted', 'shortlisted', 'accepted', 'waitlisted'];

    private const PROJECT_FK_INDEX = 'applications_project_id_index';

    private const LEGACY_UNIQUE = 'applications_project_id_applicant_id_unique';

    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            if (! Schema::hasColumn('applications', 'project_version')) {
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
                $table->json('application_data')->nullable()->after('withdrawn_at');
            }

            if (! Schema::hasColumn('applications', 'recommendation_id')) {
                $table->foreignId('recommendation_id')->nullable()->after('application_data')
                    ->constrained('recommendations')->nullOnDelete();
            }

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
                $table->string('request_fingerprint', 64)->nullable()->after('idempotency_key');
            }

            if (! Schema::hasColumn('applications', 'active_key')) {
                $table->unsignedTinyInteger('active_key')->nullable()->after('request_fingerprint');
            }
        });

        if (! $this->hasIndex(self::PROJECT_FK_INDEX)) {
            Schema::table('applications', function (Blueprint $table) {
                $table->index('project_id', self::PROJECT_FK_INDEX);
            });
        }

        DB::table('applications')
            ->whereNull('active_key')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->update(['active_key' => 1]);

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

    private function hasIndex(string $name): bool
    {
        return in_array(
            $name,
            collect(Schema::getIndexes('applications'))->pluck('name')->all(),
            true
        );
    }
};
