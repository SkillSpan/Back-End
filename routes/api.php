<?php

use App\Http\Controllers\Api\Admin\ModerationController as AdminModerationController;
use App\Http\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\Admin\ProjectController as AdminProjectController;
use App\Http\Controllers\Api\Admin\RubricController as AdminRubricController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BaselineAssessmentController;
use App\Http\Controllers\Api\CareerRoleController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\Company\CompanyDashboardController;
use App\Http\Controllers\Api\Company\CompanyProjectController;
use App\Http\Controllers\Api\Company\OrganizationAccountController;
use App\Http\Controllers\Api\Company\OrganizationProfileController;
use App\Http\Controllers\Api\Company\TalentEngagementController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\EvaluationDisputeController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\IntelligenceController;
use App\Http\Controllers\Api\Internal\BaselineItemsController;
use App\Http\Controllers\Api\LearnerMessageController;
use App\Http\Controllers\Api\MentorStudentController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProfessionalRecordController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectManagementController;
use App\Http\Controllers\Api\ProjectMatchingController;
use App\Http\Controllers\Api\ProjectTeamController;
use App\Http\Controllers\Api\ProjectWorkspaceController;
use App\Http\Controllers\Api\ReadinessController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\RecommendationFeedbackController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\RubricController;
use App\Http\Controllers\Api\SavedCareerRoleController;
use App\Http\Controllers\Api\SelfReflectionController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\SkillMatchController;
use App\Http\Controllers\Api\SkillsController;
use App\Http\Controllers\Api\SubmissionController;
use App\Http\Controllers\Api\SupportRequestController;
use App\Http\Controllers\Api\SystemPulseController;
use App\Http\Controllers\Api\TalentDiscoveryController;
use App\Http\Controllers\Api\TeamInvitationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/register/organization', [AuthController::class, 'registerOrganization'])->middleware('throttle:10,1');
        Route::post('/check-email', [AuthController::class, 'checkEmail'])->middleware('throttle:10,1');
        Route::post('/verify', [AuthController::class, 'verify'])->middleware('throttle:10,1');
        Route::post('/resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:10,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
        Route::post('/login/google', [AuthController::class, 'loginWithGoogle'])->middleware('throttle:20,1');
        Route::post('/login/organization', [AuthController::class, 'loginOrganization'])->middleware('throttle:20,1');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:10,1');
        Route::post('/forgot-password/resend', [AuthController::class, 'resendPasswordReset'])->middleware('throttle:10,1');
        Route::post('/forgot-password/verify', [AuthController::class, 'verifyPasswordReset'])->middleware('throttle:10,1');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:10,1');
    });

    Route::middleware(['auth:sanctum', 'account.active'])->prefix('auth')->group(function () {
        Route::patch('/me', [AuthController::class, 'updateMe'])->name('auth.me.update');
        Route::post('/change-password', [AuthController::class, 'changePassword'])
            ->middleware('throttle:10,1')
            ->name('auth.password.change');
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    });

    Route::middleware(['auth:sanctum', 'account.active', 'role:learner'])->prefix('profile')->group(function () {
        Route::post('/', [ProfileController::class, 'store']);
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::post('/avatar', [ProfileController::class, 'storeAvatar']);
        Route::delete('/avatar', [ProfileController::class, 'removeAvatar']);
        Route::get('/avatar', [ProfileController::class, 'avatar'])->name('learner.profile.avatar');
    });

    Route::middleware(['auth:sanctum', 'account.active', 'organization.approved', 'role:company_admin'])
        ->prefix('profile')
        ->group(function () {
            Route::get('/me', [OrganizationProfileController::class, 'show'])
                ->name('company.profile.show');
            Route::patch('/me', [OrganizationProfileController::class, 'update'])
                ->name('company.profile.update');
        });

    Route::middleware(['auth:sanctum', 'account.active', 'organization.approved', 'role:company_admin'])
        ->group(function () {
            Route::post('/organizations/{organization}/deactivate', [OrganizationAccountController::class, 'deactivate'])
                ->name('company.organization.deactivate');
        });

    Route::middleware(['auth:sanctum', 'account.active', 'admin'])->prefix('admin')->group(function () {
        Route::get('/organizations', [AdminOrganizationController::class, 'index']);
        Route::get('/organizations/{organization}', [AdminOrganizationController::class, 'show']);
        Route::get('/organizations/{organization}/proof-file', [AdminOrganizationController::class, 'downloadProofFile'])
            ->name('admin.organizations.proof-file');
        Route::post('/organizations/{organization}/approve', [AdminOrganizationController::class, 'approve']);
        Route::post('/organizations/{organization}/reject', [AdminOrganizationController::class, 'reject']);

        Route::get('/projects', [AdminProjectController::class, 'index'])
            ->name('admin.projects.index');
        Route::get('/projects/{project}', [AdminProjectController::class, 'show'])
            ->name('admin.projects.show')
            ->whereNumber('project');
        Route::post('/projects', [AdminProjectController::class, 'store'])
            ->name('admin.projects.store');
        Route::patch('/projects/{project}', [AdminProjectController::class, 'update'])
            ->name('admin.projects.update')
            ->whereNumber('project');
        Route::post('/projects/{project}/cancel', [AdminProjectController::class, 'cancel'])
            ->name('admin.projects.cancel')
            ->whereNumber('project');
    });

    Route::middleware(['auth:sanctum', 'account.active', 'role:learner'])->group(function () {
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])
            ->name('projects.show')
            ->whereNumber('project');
        Route::get('/projects/{project}/recommendation', [RecommendationController::class, 'showForProject'])
            ->name('projects.recommendation.show')
            ->whereNumber('project');

        Route::post('/projects/{project}/match', [ProjectMatchingController::class, 'match'])
            ->name('projects.match')
            ->whereNumber('project')
            ->middleware('throttle:project-matching');

        Route::get('/recommendations', [RecommendationController::class, 'index'])
            ->name('recommendations.index');

        Route::post('/projects/{project}/applications', [ApplicationController::class, 'store'])
            ->name('projects.applications.store')
            ->whereNumber('project');
        Route::get('/applications', [ApplicationController::class, 'index'])
            ->name('applications.index');
        Route::post('/applications/{application}/withdraw', [ApplicationController::class, 'withdraw'])
            ->name('applications.withdraw')
            ->whereNumber('application');
        Route::post('/recommendations/{recommendation}/feedback', [RecommendationFeedbackController::class, 'store'])
            ->name('recommendations.feedback')
            ->whereNumber('recommendation');

        Route::post('/readiness/calculate', [ReadinessController::class, 'calculate'])
            ->middleware('throttle:readiness-calculate');
        Route::get('/readiness/latest', [ReadinessController::class, 'latest']);

        Route::post('/skill-match', [SkillMatchController::class, 'store'])
            ->middleware('throttle:readiness-calculate');

        Route::post('/intelligence/calculate', [IntelligenceController::class, 'calculate'])
            ->middleware('throttle:intelligence-calculate');
        Route::get('/intelligence/latest', [IntelligenceController::class, 'latest']);
    });

    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::get('/projects/{project}/applications', [ApplicationController::class, 'indexForProject'])
            ->name('projects.applications.index')
            ->whereNumber('project');
        Route::patch('/projects/{project}/applications/{application}', [ApplicationController::class, 'decide'])
            ->name('projects.applications.decide')
            ->whereNumber('project')
            ->whereNumber('application');

        Route::get('/applications/{application}', [ApplicationController::class, 'show'])
            ->name('applications.show')
            ->whereNumber('application');

        Route::post('/applications/{application}/decision', [ApplicationController::class, 'decideForApplication'])
            ->name('applications.decision')
            ->whereNumber('application');
    });

    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::post('/projects', [ProjectManagementController::class, 'store'])
            ->name('projects.store');

        Route::patch('/projects/{project}', [ProjectManagementController::class, 'update'])
            ->name('projects.update')
            ->whereNumber('project');

        Route::post('/projects/{project}/submit', [ProjectManagementController::class, 'submit'])
            ->name('projects.submit')
            ->whereNumber('project');

        Route::post('/projects/{project}/open', [ProjectManagementController::class, 'open'])
            ->name('projects.open')
            ->whereNumber('project');

        Route::post('/projects/{project}/clone', [ProjectManagementController::class, 'duplicate'])
            ->name('projects.clone')
            ->whereNumber('project');

        Route::post('/projects/{project}/transitions', [ProjectManagementController::class, 'transition'])
            ->name('projects.transitions')
            ->whereNumber('project');

        Route::post('/projects/{project}/start', [ProjectManagementController::class, 'start'])
            ->name('projects.start')
            ->whereNumber('project');

        Route::post('/projects/{project}/team/assignments', [ProjectTeamController::class, 'store'])
            ->name('projects.team.assignments.store')
            ->whereNumber('project');

        Route::get('/projects/{project}/team', [ProjectTeamController::class, 'show'])
            ->name('projects.team.show')
            ->whereNumber('project');

        Route::delete('/projects/{project}/team/assignments/{application}', [ProjectTeamController::class, 'destroy'])
            ->name('projects.team.assignments.destroy')
            ->whereNumber('project')
            ->whereNumber('application');

        Route::post('/projects/{project}/team/invitations', [ProjectTeamController::class, 'invite'])
            ->name('projects.team.invitations.store')
            ->whereNumber('project');
    });

    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::get('/team-invitations/{invitation}', [TeamInvitationController::class, 'show'])
            ->name('team-invitations.show')
            ->whereNumber('invitation');

        Route::post('/team-invitations/{invitation}/response', [TeamInvitationController::class, 'respond'])
            ->name('team-invitations.respond')
            ->whereNumber('invitation');
    });

    Route::middleware(['auth:sanctum', 'account.active', 'admin'])->prefix('admin')->group(function () {
        Route::get('/rubrics', [AdminRubricController::class, 'index'])
            ->name('admin.rubrics.index');
        Route::post('/rubrics', [AdminRubricController::class, 'store'])
            ->name('admin.rubrics.store');
        Route::post('/rubrics/{rubric}/versions', [AdminRubricController::class, 'storeVersion'])
            ->whereNumber('rubric')
            ->name('admin.rubrics.versions.store');
        Route::patch('/rubric-versions/{rubric}', [AdminRubricController::class, 'update'])
            ->whereNumber('rubric')
            ->name('admin.rubric-versions.update');
        Route::post('/rubric-versions/{rubric}/publish', [AdminRubricController::class, 'publish'])
            ->whereNumber('rubric')
            ->name('admin.rubric-versions.publish');
        Route::post('/rubric-versions/{rubric}/deactivate', [AdminRubricController::class, 'deactivate'])
            ->whereNumber('rubric')
            ->name('admin.rubric-versions.deactivate');

        Route::get('/moderation-cases', [AdminModerationController::class, 'index'])
            ->name('admin.moderation-cases.index');
        Route::post('/moderation-cases/{case}/decide', [AdminModerationController::class, 'decide'])
            ->whereNumber('case')
            ->name('admin.moderation-cases.decide');
        Route::get('/evaluation-exceptions', [AdminModerationController::class, 'exceptions'])
            ->name('admin.evaluation-exceptions.index');
        Route::post('/evaluation-exceptions', [AdminModerationController::class, 'storeException'])
            ->name('admin.evaluation-exceptions.store');
    });

    Route::middleware(['auth:sanctum', 'account.active', 'admin'])->group(function () {
        Route::post('/projects/{project}/approve', [ProjectManagementController::class, 'approve'])
            ->name('projects.approve')
            ->whereNumber('project');

        Route::post('/projects/{project}/request-changes', [ProjectManagementController::class, 'requestChanges'])
            ->name('projects.request-changes')
            ->whereNumber('project');

        Route::post('/projects/{project}/reject', [ProjectManagementController::class, 'reject'])
            ->name('projects.reject')
            ->whereNumber('project');
    });

    Route::middleware(['auth:sanctum', 'account.active', 'role:learner'])->group(function () {
        Route::post('/baseline-assessments', [BaselineAssessmentController::class, 'start']);
        Route::get('/baseline-assessments/{assessment}', [BaselineAssessmentController::class, 'show']);
        Route::patch('/baseline-assessments/{assessment}', [BaselineAssessmentController::class, 'progress']);
        Route::post('/baseline-assessments/{assessment}/submit', [BaselineAssessmentController::class, 'submit'])
            ->middleware('throttle:baseline-submit');
    });

    Route::middleware(['auth:sanctum', 'account.active', 'role:learner'])->prefix('assistant')->group(function () {
        Route::post('/ask', [AssistantController::class, 'ask'])
            ->middleware('throttle:assistant-ask');

        Route::put('/interactions/{interaction}/report', [AssistantController::class, 'report']);
    });

    Route::middleware(['auth:sanctum', 'account.active', 'role:learner'])
        ->prefix('support')
        ->group(function () {
            Route::post('/requests', [SupportRequestController::class, 'store'])
                ->middleware('throttle:support-write');

            Route::get('/requests', [SupportRequestController::class, 'index']);

            Route::get('/requests/{supportRequest}', [SupportRequestController::class, 'show'])
                ->whereNumber('supportRequest');

            Route::post('/requests/{supportRequest}/messages', [SupportRequestController::class, 'message'])
                ->whereNumber('supportRequest')
                ->middleware('throttle:support-write');

            Route::get('/avatar/{user}', [SupportRequestController::class, 'avatar'])
                ->whereNumber('user');
        });

    Route::prefix('reference')->group(function () {
        Route::get('/universities', [ReferenceController::class, 'universities']);
        Route::get('/specializations', [ReferenceController::class, 'specializations']);
        Route::get('/countries', [ReferenceController::class, 'countries']);
        Route::get('/countries/{country}/universities', [ReferenceController::class, 'countryUniversities']);
    });

    Route::get('/_pulse/7c1f9e2a', [SystemPulseController::class, 'show']);

    Route::middleware(['auth:sanctum', 'account.active'])->prefix('skills')->group(function () {
        Route::get('/taxonomy', [SkillsController::class, 'taxonomy']);
        Route::get('/matrix', [SkillsController::class, 'matrix']);
        Route::post('/matrix', [SkillsController::class, 'store']);
        Route::put('/matrix/{id}', [SkillsController::class, 'update']);
    });

    Route::middleware(['auth:sanctum', 'account.active', 'role:learner'])
        ->prefix('learner')
        ->group(function () {
            Route::delete('/skills/{skill}', [SkillsController::class, 'removeFromLearner'])
                ->name('learner.skills.destroy')
                ->whereNumber('skill');

            Route::get('/messages', [LearnerMessageController::class, 'index'])
                ->name('learner.messages.index');
        });

    Route::middleware(['auth:sanctum', 'account.active'])->prefix('evidence')->group(function () {
        Route::post('/', [EvidenceController::class, 'store'])
            ->middleware(['role:learner', 'throttle:evidence-upload']);
        Route::get('/', [EvidenceController::class, 'index'])
            ->middleware('role:learner');
        Route::get('/{id}', [EvidenceController::class, 'show']);
        Route::put('/{id}/review', [EvidenceController::class, 'review'])
            ->middleware('role:admin');
    });

    Route::middleware(['auth:sanctum', 'account.active'])->prefix('career-roles')->group(function () {
        Route::get('/', [CareerRoleController::class, 'index']);
        Route::get('/{id}', [CareerRoleController::class, 'show']);
        Route::get('/{id}/skills', [CareerRoleController::class, 'skills']);
    });

    Route::middleware(['auth:sanctum', 'account.active'])->prefix('saved-career-roles')->group(function () {
        Route::get('/', [SavedCareerRoleController::class, 'index'])
            ->name('saved-career-roles.index');
        Route::put('/{id}', [SavedCareerRoleController::class, 'store'])
            ->whereNumber('id')
            ->name('saved-career-roles.store');
        Route::delete('/{id}', [SavedCareerRoleController::class, 'destroy'])
            ->whereNumber('id')
            ->name('saved-career-roles.destroy');
    });

    Route::middleware(['auth:sanctum', 'account.active', 'role:company_admin'])
        ->get('/rubrics', [RubricController::class, 'index'])
        ->name('rubrics.index');

    Route::middleware(['auth:sanctum', 'account.active', 'organization.approved'])->prefix('organization')->group(function () {
        Route::get('/profile', [OrganizationController::class, 'profile']);
    });

    Route::middleware(['auth:sanctum', 'account.active', 'organization.approved', 'role:company_admin'])
        ->prefix('talent')
        ->group(function () {
            Route::get('/', [TalentDiscoveryController::class, 'index']);
            Route::get('/filters', [TalentDiscoveryController::class, 'filters']);

            Route::post('/compare', [TalentEngagementController::class, 'compare'])
                ->name('talent.compare');

            Route::get('/lists', [TalentEngagementController::class, 'indexLists'])
                ->name('talent.lists.index');
            Route::post('/lists', [TalentEngagementController::class, 'storeList'])
                ->name('talent.lists.store');
            Route::get('/lists/{list}', [TalentEngagementController::class, 'showList'])
                ->whereNumber('list')
                ->name('talent.lists.show');
            Route::patch('/lists/{list}', [TalentEngagementController::class, 'updateList'])
                ->whereNumber('list')
                ->name('talent.lists.update');
            Route::delete('/lists/{list}', [TalentEngagementController::class, 'destroyList'])
                ->whereNumber('list')
                ->name('talent.lists.destroy');
            Route::post('/lists/{list}/candidates', [TalentEngagementController::class, 'storeListCandidate'])
                ->whereNumber('list')
                ->name('talent.lists.candidates.store');
            Route::delete('/lists/{list}/candidates/{student}', [TalentEngagementController::class, 'destroyListCandidate'])
                ->whereNumber('list')
                ->whereNumber('student')
                ->name('talent.lists.candidates.destroy');

            Route::get('/funnel', [TalentEngagementController::class, 'funnel'])
                ->name('talent.funnel');

            Route::get('/candidates/{student}/notes', [TalentEngagementController::class, 'indexNotes'])
                ->whereNumber('student')
                ->name('talent.candidates.notes.index');
            Route::post('/candidates/{student}/notes', [TalentEngagementController::class, 'storeNote'])
                ->whereNumber('student')
                ->name('talent.candidates.notes.store');
            Route::post('/candidates/{student}/contact', [TalentEngagementController::class, 'storeContact'])
                ->whereNumber('student')
                ->name('talent.candidates.contact');

            Route::post('/contact-all', [TalentEngagementController::class, 'storeContactAll'])
                ->name('talent.contact.all');

            Route::get('/{student}', [TalentDiscoveryController::class, 'show'])
                ->whereNumber('student');
        });

    Route::middleware(['auth:sanctum', 'account.active', 'organization.approved', 'role:company_admin'])
        ->prefix('company')
        ->group(function () {
            Route::get('/dashboard', [CompanyDashboardController::class, 'show'])
                ->name('company.dashboard');

            Route::get('/projects', [CompanyProjectController::class, 'index'])
                ->name('company.projects.index');
            Route::get('/projects/{project}', [CompanyProjectController::class, 'show'])
                ->whereNumber('project')
                ->name('company.projects.show');

            Route::post('/profile/documents', [OrganizationProfileController::class, 'uploadDocument'])
                ->name('company.profile.documents.store');
            Route::get('/profile/documents/{document}', [OrganizationProfileController::class, 'downloadDocument'])
                ->whereNumber('document')
                ->name('company.profile.documents.show');

            Route::post('/profile/logo', [OrganizationProfileController::class, 'uploadLogo'])
                ->name('company.profile.logo.store');
            Route::delete('/profile/logo', [OrganizationProfileController::class, 'deleteLogo'])
                ->name('company.profile.logo.destroy');
        });

    Route::middleware(['auth:sanctum', 'account.active', 'mentor'])->prefix('mentor')->group(function () {
        Route::get('/students', [MentorStudentController::class, 'students']);
        Route::get('/students/{student}', [MentorStudentController::class, 'studentSummary']);
        Route::post('/connections', [MentorStudentController::class, 'connect']);
        Route::get('/connections', [MentorStudentController::class, 'connections']);
        Route::patch('/connections/{connection}', [MentorStudentController::class, 'updateConnection']);
    });

    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::post('/connections/{connection}/conversations', [ConversationController::class, 'store']);
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'sendMessage']);
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markAsRead']);
        Route::get('/conversations/{conversation}/status', [ConversationController::class, 'status']);
        Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy']);

        Route::post('/conversations/{conversation}/chatbot/messages', [ChatbotController::class, 'send']);
        Route::get('/conversations/{conversation}/chatbot/messages', [ChatbotController::class, 'messages']);
    });

    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::get('/projects/{project}/workspace', [ProjectWorkspaceController::class, 'workspace'])
            ->whereNumber('project')
            ->name('projects.workspace.show');
        Route::get('/projects/{project}/milestones', [ProjectWorkspaceController::class, 'milestones'])
            ->whereNumber('project')
            ->name('projects.milestones.index');
        Route::post('/projects/{project}/milestones', [ProjectWorkspaceController::class, 'storeMilestone'])
            ->whereNumber('project')
            ->name('projects.milestones.store');
        Route::post('/projects/{project}/milestones/reorder', [ProjectWorkspaceController::class, 'reorderMilestones'])
            ->whereNumber('project')
            ->name('projects.milestones.reorder');
        Route::patch('/milestones/{milestone}', [ProjectWorkspaceController::class, 'updateMilestone'])
            ->whereNumber('milestone')
            ->name('milestones.update');
        Route::post('/milestones/{milestone}/extension-requests', [ProjectWorkspaceController::class, 'storeExtension'])
            ->whereNumber('milestone')
            ->name('milestones.extension-requests.store');
        Route::get('/projects/{project}/extension-requests', [ProjectWorkspaceController::class, 'indexExtensions'])
            ->whereNumber('project')
            ->name('projects.extension-requests.index');
        Route::patch('/extension-requests/{extension}', [ProjectWorkspaceController::class, 'decideExtension'])
            ->whereNumber('extension')
            ->name('extension-requests.update');

        Route::post('/projects/{project}/submissions', [SubmissionController::class, 'store'])
            ->whereNumber('project')
            ->name('projects.submissions.store');
        Route::get('/projects/{project}/submissions', [SubmissionController::class, 'index'])
            ->whereNumber('project')
            ->name('projects.submissions.index');
        Route::get('/submissions/{submission}', [SubmissionController::class, 'show'])
            ->whereNumber('submission')
            ->name('submissions.show');
        Route::get('/submissions/{submission}/file', [SubmissionController::class, 'file'])
            ->whereNumber('submission')
            ->name('submissions.file');
        Route::get('/submissions/{submission}/first-pass-check', [SubmissionController::class, 'firstPass'])
            ->whereNumber('submission')
            ->name('submissions.first-pass-check');

        Route::get('/evaluations/tasks', [EvaluationController::class, 'tasks'])
            ->name('evaluations.tasks.index');
        Route::post('/evaluations', [EvaluationController::class, 'store'])
            ->name('evaluations.store');
        Route::get('/evaluations/{evaluation}', [EvaluationController::class, 'show'])
            ->whereNumber('evaluation')
            ->name('evaluations.show');
        Route::patch('/evaluations/{evaluation}', [EvaluationController::class, 'update'])
            ->whereNumber('evaluation')
            ->name('evaluations.update');
        Route::post('/evaluations/{evaluation}/submit', [EvaluationController::class, 'submit'])
            ->whereNumber('evaluation')
            ->name('evaluations.submit');
        Route::post('/evaluations/{evaluation}/conflict', [EvaluationController::class, 'conflict'])
            ->whereNumber('evaluation')
            ->name('evaluations.conflict');
        Route::post('/evaluations/{evaluation}/corrections', [EvaluationController::class, 'correction'])
            ->whereNumber('evaluation')
            ->name('evaluations.corrections.store');
        Route::post('/evaluations/{evaluation}/disputes', [EvaluationDisputeController::class, 'store'])
            ->whereNumber('evaluation')
            ->name('evaluations.disputes.store');
        Route::get('/evaluations/{evaluation}/disputes', [EvaluationDisputeController::class, 'index'])
            ->whereNumber('evaluation')
            ->name('evaluations.disputes.index');
        Route::get('/learner/disputes', [EvaluationDisputeController::class, 'mine'])
            ->name('learner.disputes.index');
        Route::get('/projects/{project}/evaluations', [EvaluationController::class, 'project'])
            ->whereNumber('project')
            ->name('projects.evaluations.index');
        Route::get('/learner/evaluations', [EvaluationController::class, 'results'])
            ->name('learner.evaluations.index');

        Route::get('/projects/{project}/self-reflection', [SelfReflectionController::class, 'show'])
            ->whereNumber('project')
            ->name('projects.self-reflection.show');
        Route::post('/projects/{project}/self-reflection', [SelfReflectionController::class, 'store'])
            ->whereNumber('project')
            ->name('projects.self-reflection.store');
        Route::post('/projects/{project}/self-reflection/submit', [SelfReflectionController::class, 'submit'])
            ->whereNumber('project')
            ->name('projects.self-reflection.submit');

        Route::get('/learner/professional-record', [ProfessionalRecordController::class, 'show'])
            ->name('learner.professional-record.show');
        Route::get('/learner/professional-record/export', [ProfessionalRecordController::class, 'export'])
            ->name('learner.professional-record.export');
        Route::put('/learner/professional-record/visibility', [ProfessionalRecordController::class, 'updateVisibility'])
            ->name('learner.professional-record.visibility');
        Route::get('/learner/professional-record/share-links', [ProfessionalRecordController::class, 'shareLinks'])
            ->name('learner.professional-record.share-links.index');
        Route::post('/learner/professional-record/share-links', [ProfessionalRecordController::class, 'createShareLink'])
            ->name('learner.professional-record.share-links.store');
        Route::delete('/learner/professional-record/share-links/{link}', [ProfessionalRecordController::class, 'revokeShareLink'])
            ->whereNumber('link')
            ->name('learner.professional-record.share-links.destroy');
        Route::post('/learner/professional-record/items/{item}/verification', [ProfessionalRecordController::class, 'verification'])
            ->whereNumber('item')
            ->name('learner.professional-record.items.verification');
    });

    Route::middleware(['auth:sanctum', 'account.active'])->prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::get('/preferences', [NotificationController::class, 'preferences']);
        Route::put('/preferences', [NotificationController::class, 'updatePreference']);
        Route::post('/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::patch('/{notification}/read', [NotificationController::class, 'markAsRead']);
    });

    Route::post('/setup/create-admin', [SetupController::class, 'createAdmin'])
        ->middleware('throttle:5,1');

    Route::post('/setup/create-mentor', [SetupController::class, 'createMentor'])
        ->middleware('throttle:10,1');

    Route::prefix('internal')->group(function () {
        Route::get('/baseline-items', [BaselineItemsController::class, 'index'])
            ->middleware('throttle:60,1');
    });

    Route::get('/r/{token}', [ProfessionalRecordController::class, 'publicRecord'])
        ->middleware('throttle:60,1')
        ->name('professional-record.public');

    Route::get('/verify/{reference}', [ProfessionalRecordController::class, 'verify'])
        ->middleware('throttle:60,1')
        ->name('professional-record.verify');
});
