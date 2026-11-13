<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->milestones();
        $this->submissions();
        $this->rubrics();
        $this->evaluations();
        $this->record();
        $this->workflowTables();
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_record_verifications');
        Schema::dropIfExists('professional_record_share_accesses');
        Schema::dropIfExists('professional_record_share_links');
        Schema::dropIfExists('professional_record_section_visibility');
        Schema::dropIfExists('evaluation_exceptions');
        Schema::dropIfExists('evaluation_correction_requests');
        Schema::dropIfExists('evaluation_moderation_cases');
        Schema::dropIfExists('self_reflections');
        Schema::dropIfExists('project_delegates');
        Schema::dropIfExists('evaluation_tasks');
        Schema::dropIfExists('submission_first_pass_checks');
        Schema::dropIfExists('project_milestone_changes');
        Schema::dropIfExists('rubric_dimension_skills');

        Schema::table('professional_record_items', function (Blueprint $table) {
            $table->dropForeign(['evaluator_id']);
        });

        Schema::table('professional_record_items', function (Blueprint $table) {
            $table->dropIndex('professional_record_items_student_type_status_index');
            $table->dropUnique('professional_record_items_idempotency_key_unique');
            $table->dropColumn([
                'item_type',
                'source_version',
                'project_type',
                'role',
                'contribution',
                'issuer',
                'supporting_reference',
                'status',
                'idempotency_key',
                'evaluator_id',
            ]);
        });

        Schema::table('rubric_dimensions', function (Blueprint $table) {
            $table->dropColumn(['score_min', 'score_max', 'indicators', 'order_index']);
        });

        Schema::table('rubrics', function (Blueprint $table) {
            $table->dropForeign(['family_id']);
            $table->dropForeign(['approved_by']);
        });

        Schema::table('rubrics', function (Blueprint $table) {
            $table->dropIndex('rubrics_family_version_index');
            $table->dropColumn([
                'description',
                'applicable_project_types',
                'score_min',
                'score_max',
                'family_id',
                'approved_by',
                'approval_reason',
                'effective_date',
                'deactivated_at',
                'deactivation_reason',
            ]);
        });

        Schema::table('evaluation_disputes', function (Blueprint $table) {
            $table->dropIndex('evaluation_disputes_evaluation_status_index');
            $table->dropColumn([
                'disputed_criteria',
                'rubric_version',
                'evidence',
                'classification',
                'corrective_action',
                'outcome_reason',
                'exception_id',
            ]);
        });

        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropForeign(['participant_id']);
            $table->dropForeign(['finalized_by']);
            $table->dropForeign(['supersedes_evaluation_id']);
            $table->dropForeign(['superseded_by_evaluation_id']);
        });

        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropIndex('evaluations_participant_status_index');
            $table->dropIndex('evaluations_project_status_index');
            $table->dropColumn([
                'participant_id',
                'evaluator_type',
                'team_outcome',
                'individual_contribution',
                'improvement_guidance',
                'confidential_notes',
                'conflict_declared',
                'conflict_declared_at',
                'conflict_mitigation_approved',
                'submitted_at',
                'finalized_by',
                'calculation_inputs',
                'supersedes_evaluation_id',
                'superseded_by_evaluation_id',
                'moderation_status',
            ]);
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropForeign(['project_team_id']);
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropUnique('submissions_contributor_idempotency_unique');
            $table->dropIndex('submissions_milestone_contributor_index');
            $table->dropIndex('submissions_project_current_index');
            $table->dropColumn([
                'project_team_id',
                'submission_type',
                'original_filename',
                'file_size',
                'content_type',
                'is_current',
                'idempotency_key',
                'first_pass_status',
            ]);
        });

        Schema::table('milestone_extension_requests', function (Blueprint $table) {
            $table->dropIndex('milestone_extension_milestone_status_index');
            $table->dropColumn(['decision_reason', 'applied_deadline']);
        });

        Schema::table('project_milestones', function (Blueprint $table) {
            $table->dropForeign(['completed_by']);
        });

        Schema::table('project_milestones', function (Blueprint $table) {
            $table->dropIndex('project_milestones_project_order_index');
            $table->dropIndex('project_milestones_project_status_index');
            $table->dropColumn(['submission_type', 'completed_at', 'completed_by']);
        });
    }

    private function milestones(): void
    {
        if (! Schema::hasColumn('project_milestones', 'submission_type')) {
            Schema::table('project_milestones', function (Blueprint $table) {
                $table->string('submission_type')->default('any')->after('required_deliverables');
                $table->timestamp('completed_at')->nullable()->after('status');
                $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
                $table->index(['project_id', 'order_index'], 'project_milestones_project_order_index');
                $table->index(['project_id', 'status'], 'project_milestones_project_status_index');
            });
        }

        if (! Schema::hasColumn('milestone_extension_requests', 'decision_reason')) {
            Schema::table('milestone_extension_requests', function (Blueprint $table) {
                $table->text('decision_reason')->nullable()->after('status');
                $table->date('applied_deadline')->nullable()->after('decision_reason');
                $table->index(['project_milestone_id', 'status'], 'milestone_extension_milestone_status_index');
            });
        }

        if (! Schema::hasTable('project_milestone_changes')) {
            Schema::create('project_milestone_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_milestone_id')->constrained()->cascadeOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('field');
                $table->text('old_value')->nullable();
                $table->text('new_value')->nullable();
                $table->timestamps();
                $table->index(['project_milestone_id', 'created_at'], 'project_milestone_changes_milestone_created_index');
            });
        }
    }

    private function submissions(): void
    {
        if (! Schema::hasColumn('submissions', 'idempotency_key')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->foreignId('project_team_id')->nullable()->after('project_milestone_id')->constrained('project_teams')->nullOnDelete();
                $table->string('submission_type')->default('repository_link')->after('version');
                $table->string('original_filename')->nullable()->after('file_path');
                $table->unsignedBigInteger('file_size')->nullable()->after('original_filename');
                $table->string('content_type')->nullable()->after('file_size');
                $table->boolean('is_current')->default(true)->after('status');
                $table->string('idempotency_key', 120)->nullable()->after('is_current');
                $table->string('first_pass_status')->nullable()->after('idempotency_key');
                $table->index(['project_milestone_id', 'contributor_id'], 'submissions_milestone_contributor_index');
                $table->index(['project_id', 'is_current'], 'submissions_project_current_index');
                $table->unique(['contributor_id', 'idempotency_key'], 'submissions_contributor_idempotency_unique');
            });
        }

        if (! Schema::hasTable('submission_first_pass_checks')) {
            Schema::create('submission_first_pass_checks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('submission_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('status')->default('pending');
                $table->json('items')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'created_at'], 'first_pass_checks_status_created_index');
            });
        }
    }

    private function rubrics(): void
    {
        if (! Schema::hasColumn('rubrics', 'family_id')) {
            Schema::table('rubrics', function (Blueprint $table) {
                $table->text('description')->nullable()->after('title');
                $table->json('applicable_project_types')->nullable()->after('description');
                $table->decimal('score_min', 5, 2)->default(0)->after('applicable_project_types');
                $table->decimal('score_max', 5, 2)->default(100)->after('score_min');
                $table->foreignId('family_id')->nullable()->after('id')->constrained('rubrics')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
                $table->text('approval_reason')->nullable()->after('approved_by');
                $table->date('effective_date')->nullable()->after('approval_reason');
                $table->timestamp('deactivated_at')->nullable()->after('published_at');
                $table->text('deactivation_reason')->nullable()->after('deactivated_at');
                $table->index(['family_id', 'version'], 'rubrics_family_version_index');
            });
        }

        Schema::table('rubrics', function (Blueprint $table) {
            $table->enum('status', ['draft', 'published', 'retired', 'deactivated'])->default('draft')->change();
        });

        if (! Schema::hasColumn('rubric_dimensions', 'order_index')) {
            Schema::table('rubric_dimensions', function (Blueprint $table) {
                $table->decimal('score_min', 5, 2)->default(0)->after('weight');
                $table->decimal('score_max', 5, 2)->default(100)->after('score_min');
                $table->json('indicators')->nullable()->after('score_max');
                $table->unsignedInteger('order_index')->default(0)->after('indicators');
            });
        }

        if (! Schema::hasTable('rubric_dimension_skills')) {
            Schema::create('rubric_dimension_skills', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rubric_dimension_id')->constrained()->cascadeOnDelete();
                $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['rubric_dimension_id', 'skill_id'], 'rubric_dimension_skills_unique');
            });
        }
    }

    private function evaluations(): void
    {
        if (! Schema::hasColumn('evaluations', 'participant_id')) {
            Schema::table('evaluations', function (Blueprint $table) {
                $table->foreignId('participant_id')->nullable()->after('submission_id')->constrained('users')->nullOnDelete();
                $table->string('evaluator_type')->default('mentor')->after('evaluator_id');
                $table->text('team_outcome')->nullable()->after('overall_score');
                $table->text('individual_contribution')->nullable()->after('team_outcome');
                $table->text('improvement_guidance')->nullable()->after('individual_contribution');
                $table->text('confidential_notes')->nullable()->after('improvement_guidance');
                $table->boolean('conflict_declared')->default(false)->after('confidential_notes');
                $table->timestamp('conflict_declared_at')->nullable()->after('conflict_declared');
                $table->boolean('conflict_mitigation_approved')->default(false)->after('conflict_declared_at');
                $table->timestamp('submitted_at')->nullable()->after('conflict_mitigation_approved');
                $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
                $table->json('calculation_inputs')->nullable()->after('finalized_by');
                $table->foreignId('supersedes_evaluation_id')->nullable()->after('calculation_inputs')->constrained('evaluations')->nullOnDelete();
                $table->foreignId('superseded_by_evaluation_id')->nullable()->after('supersedes_evaluation_id')->constrained('evaluations')->nullOnDelete();
                $table->string('moderation_status')->nullable()->after('moderation_reason');
                $table->index(['participant_id', 'status'], 'evaluations_participant_status_index');
                $table->index(['project_id', 'status'], 'evaluations_project_status_index');
            });
        }

        Schema::table('evaluations', function (Blueprint $table) {
            $table->enum('status', ['draft', 'submitted', 'moderation', 'finalized', 'disputed', 'superseded', 'voided', 'moderated'])->default('draft')->change();
        });

        if (! Schema::hasColumn('evaluation_disputes', 'disputed_criteria')) {
            Schema::table('evaluation_disputes', function (Blueprint $table) {
                $table->json('disputed_criteria')->nullable()->after('grounds');
                $table->unsignedInteger('rubric_version')->nullable()->after('disputed_criteria');
                $table->text('evidence')->nullable()->after('rubric_version');
                $table->string('classification')->nullable()->after('status');
                $table->string('corrective_action')->nullable()->after('classification');
                $table->text('outcome_reason')->nullable()->after('corrective_action');
                $table->unsignedBigInteger('exception_id')->nullable()->after('outcome_reason');
                $table->index(['evaluation_id', 'status'], 'evaluation_disputes_evaluation_status_index');
            });
        }
    }

    private function record(): void
    {
        if (! Schema::hasColumn('professional_record_items', 'item_type')) {
            Schema::table('professional_record_items', function (Blueprint $table) {
                $table->string('item_type')->default('project')->after('source_id');
                $table->string('source_version')->nullable()->after('item_type');
                $table->string('project_type')->nullable()->after('source_version');
                $table->string('role')->nullable()->after('project_type');
                $table->text('contribution')->nullable()->after('role');
                $table->string('issuer')->nullable()->after('contribution');
                $table->string('supporting_reference')->nullable()->after('issuer');
                $table->string('status')->default('active')->after('verification_state');
                $table->string('idempotency_key', 160)->nullable()->after('status');
                $table->foreignId('evaluator_id')->nullable()->after('idempotency_key')->constrained('users')->nullOnDelete();
                $table->index(['student_profile_id', 'item_type', 'status'], 'professional_record_items_student_type_status_index');
                $table->unique('idempotency_key', 'professional_record_items_idempotency_key_unique');
            });
        }

        if (! Schema::hasTable('professional_record_section_visibility')) {
            Schema::create('professional_record_section_visibility', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
                $table->string('section');
                $table->string('visibility')->default('private');
                $table->timestamps();
                $table->unique(['student_profile_id', 'section'], 'record_section_visibility_unique');
            });
        }

        if (! Schema::hasTable('professional_record_share_links')) {
            Schema::create('professional_record_share_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
                $table->string('token_hash', 64)->unique();
                $table->json('sections');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('last_accessed_at')->nullable();
                $table->unsignedInteger('access_count')->default(0);
                $table->timestamps();
                $table->index(['student_profile_id', 'revoked_at'], 'record_share_links_owner_revoked_index');
                $table->index('expires_at', 'record_share_links_expires_index');
            });
        }

        if (! Schema::hasTable('professional_record_share_accesses')) {
            Schema::create('professional_record_share_accesses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('share_link_id')->constrained('professional_record_share_links')->cascadeOnDelete();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('accessed_at');
                $table->index(['share_link_id', 'accessed_at'], 'record_share_accesses_link_time_index');
            });
        }

        if (! Schema::hasTable('professional_record_verifications')) {
            Schema::create('professional_record_verifications', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 40)->unique();
                $table->foreignId('record_item_id')->constrained('professional_record_items')->cascadeOnDelete();
                $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedInteger('access_count')->default(0);
                $table->timestamps();
                $table->index(['student_profile_id', 'revoked_at'], 'record_verifications_owner_revoked_index');
            });
        }
    }

    private function workflowTables(): void
    {
        if (! Schema::hasTable('evaluation_tasks')) {
            Schema::create('evaluation_tasks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('participant_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('submission_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('evaluator_type')->default('mentor');
                $table->foreignId('rubric_id')->nullable()->constrained()->nullOnDelete();
                $table->string('status')->default('pending');
                $table->string('routing_reason')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['submission_id', 'participant_id'], 'evaluation_tasks_submission_participant_unique');
                $table->index(['evaluator_id', 'status'], 'evaluation_tasks_evaluator_status_index');
                $table->index(['project_id', 'status'], 'evaluation_tasks_project_status_index');
            });
        }

        if (! Schema::hasTable('project_delegates')) {
            Schema::create('project_delegates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->unique(['project_id', 'user_id'], 'project_delegates_project_user_unique');
            });
        }

        if (! Schema::hasTable('self_reflections')) {
            Schema::create('self_reflections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->json('answers')->nullable();
                $table->text('learning_summary')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();
                $table->unique(['project_id', 'user_id'], 'self_reflections_project_user_unique');
            });
        }

        if (! Schema::hasTable('evaluation_correction_requests')) {
            Schema::create('evaluation_correction_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
                $table->text('reason');
                $table->string('status')->default('pending');
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->text('resolution_notes')->nullable();
                $table->timestamps();
                $table->index(['evaluation_id', 'status'], 'correction_requests_evaluation_status_index');
            });
        }

        if (! Schema::hasTable('evaluation_moderation_cases')) {
            Schema::create('evaluation_moderation_cases', function (Blueprint $table) {
                $table->id();
                $table->string('type');
                $table->foreignId('evaluation_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('dispute_id')->nullable()->constrained('evaluation_disputes')->nullOnDelete();
                $table->foreignId('correction_request_id')->nullable()->constrained('evaluation_correction_requests')->nullOnDelete();
                $table->string('source_type')->nullable()->after('correction_request_id');
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
                $table->string('status')->default('open');
                $table->string('decision')->nullable();
                $table->text('reason')->nullable();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'type'], 'moderation_cases_status_type_index');
                $table->index('evaluation_id', 'moderation_cases_evaluation_index');
            });
        }

        if (! Schema::hasTable('evaluation_exceptions')) {
            Schema::create('evaluation_exceptions', function (Blueprint $table) {
                $table->id();
                $table->string('type');
                $table->string('subject_type');
                $table->unsignedBigInteger('subject_id');
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reason');
                $table->timestamps();
                $table->index(['subject_type', 'subject_id'], 'evaluation_exceptions_subject_index');
            });
        }
    }
};
