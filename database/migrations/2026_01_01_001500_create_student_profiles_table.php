<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent on purpose. The table already exists on production (it was
        // created before the `migrations` bookkeeping table lost the row that
        // recorded it), so a plain Schema::create() dies with:
        //
        //     SQLSTATE[42S01] 1050 Table 'student_profiles' already exists
        //
        // Guarding the CREATE lets the migration be recorded on a database that
        // already has the table, while still creating it from scratch on a fresh
        // one. It does NOT rebuild: nothing is dropped.
        if (! Schema::hasTable('student_profiles')) {
            Schema::create('student_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('education')->nullable();
                $table->string('specialization')->nullable();
                $table->string('career_status')->nullable();
                $table->json('interests')->nullable();
                $table->string('availability')->nullable();
                $table->string('preferred_work_type')->nullable();
                $table->enum('visibility', ['public', 'organization_only', 'private'])->default('private');
                $table->unsignedTinyInteger('completeness_percent')->default(0);
                $table->enum('enrollment_status', ['enrolled', 'graduated', 'on_leave'])->default('enrolled');
                $table->boolean('graduation_status')->default(false);
                $table->date('graduation_date')->nullable();
                $table->foreignId('primary_career_role_id')->nullable()->constrained('career_roles')->nullOnDelete();
                $table->boolean('consent_given')->default(false);
                $table->timestamps();
            });

            return;
        }

        // The table exists but is missing this migration's own column — the
        // production table predates the field. Add just that column, guarded so
        // a replay is safe.
        if (! Schema::hasColumn('student_profiles', 'education')) {
            Schema::table('student_profiles', function (Blueprint $table) {
                $table->string('education')->nullable()->after('student_university_number');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
