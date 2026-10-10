<?php

use App\Http\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\Admin\ProjectController as AdminProjectController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BaselineAssessmentController;
use App\Http\Controllers\Api\CareerRoleController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\IntelligenceController;
use App\Http\Controllers\Api\Internal\BaselineItemsController;
use App\Http\Controllers\Api\MentorStudentController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectManagementController;
use App\Http\Controllers\Api\ProjectMatchingController;
use App\Http\Controllers\Api\ReadinessController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\RecommendationFeedbackController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\SkillMatchController;
use App\Http\Controllers\Api\SkillsController;
use App\Http\Controllers\Api\SupportRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/register/organization', [AuthController::class, 'registerOrganization'])->middleware('throttle:10,1');
        // Public on purpose: it is called from the sign-up form, before the
        // visitor has any token. Throttled because it answers "is this address
        // registered?" — see AuthController::checkEmail for the reasoning.
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
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    });

    Route::middleware(['auth:sanctum', 'account.active', 'role:learner'])->prefix('profile')->group(function () {
        Route::post('/', [ProfileController::class, 'store']);
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
    });

    Route::middleware(['auth:sanctum', 'account.active', 'admin'])->prefix('admin')->group(function () {
        Route::get('/organizations', [AdminOrganizationController::class, 'index']);
        Route::get('/organizations/{organization}', [AdminOrganizationController::class, 'show']);
        Route::get('/organizations/{organization}/proof-file', [AdminOrganizationController::class, 'downloadProofFile'])
            ->name('admin.organizations.proof-file');
        Route::post('/organizations/{organization}/approve', [AdminOrganizationController::class, 'approve']);
        Route::post('/organizations/{organization}/reject', [AdminOrganizationController::class, 'reject']);

        // ──────────────────────────────────────────────────────────────
        // Admin project management — the platform-administrator read and
        // moderation surface.
        //
        // These are READ plus CANCEL only. Every actual lifecycle move
        // (submit / approve / request-changes / reject / open) already has
        // an endpoint above and is reused as-is, so the allowed transitions,
        // the audit trail and the versioned matching snapshot have exactly
        // one writer: ProjectLifecycleService.
        //
        // The list/detail pair cannot be served by the owner or learner
        // controllers — one is owner-scoped, the other needs a student
        // profile — so an administrator could not see a `draft` at all.
        // ──────────────────────────────────────────────────────────────
        Route::get('/projects', [AdminProjectController::class, 'index'])
            ->name('admin.projects.index');
        Route::get('/projects/{project}', [AdminProjectController::class, 'show'])
            ->name('admin.projects.show')
            ->whereNumber('project');
        // Create/edit mirror the owner API's endpoints exactly, because the
        // underlying request classes and lifecycle service are the same ones.
        // They are exposed under /admin so the admin panel's session routes can
        // delegate to this one controller instead of reaching across groups.
        Route::post('/projects', [AdminProjectController::class, 'store'])
            ->name('admin.projects.store');
        Route::patch('/projects/{project}', [AdminProjectController::class, 'update'])
            ->name('admin.projects.update')
            ->whereNumber('project');
        // Soft delete: marks the project `cancelled` and keeps every
        // application, recommendation and audit entry pointing at it.
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

        // Task 10 — deterministic project-matching recommendation for one
        // learner + project. Composes the validated snapshot (Task 8) with
        // the FastAPI project-matching contract (Task 10). Gated on
        // DATA_SCIENCE_PROJECT_MATCHING_ENABLED; disabled ⇒ 503.
        Route::post('/projects/{project}/match', [ProjectMatchingController::class, 'match'])
            ->name('projects.match')
            ->whereNumber('project')
            ->middleware('throttle:project-matching');

        // Task 11 — the learner's own stored project matching
        // recommendations. Scoped to the authenticated learner.
        Route::get('/recommendations', [RecommendationController::class, 'index'])
            ->name('recommendations.index');

        // US-MATCH-02 — student project application & recommendation feedback.
        // Ownership of a specific application / recommendation is enforced in
        // ApplicationService / RecommendationFeedbackService, not by the route.
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

        // Costly: a synchronous Data Science round trip. Throttled per user so
        // one account cannot loop it, while a learner recalculating a few times
        // never notices.
        Route::post('/readiness/calculate', [ReadinessController::class, 'calculate'])
            ->middleware('throttle:readiness-calculate');
        Route::get('/readiness/latest', [ReadinessController::class, 'latest']);

        // Same synchronous Data Science call as /readiness/calculate, so it
        // shares that limiter rather than inventing a second budget.
        Route::post('/skill-match', [SkillMatchController::class, 'store'])
            ->middleware('throttle:readiness-calculate');

        // US-INT-01 — intelligence decision endpoints (skill gap +
        // readiness + roadmap in one atomic decision).
        Route::post('/intelligence/calculate', [IntelligenceController::class, 'calculate'])
            ->middleware('throttle:intelligence-calculate');
        Route::get('/intelligence/latest', [IntelligenceController::class, 'latest']);
    });

    // US-MATCH-02 — project-owner side of the application workflow. Deliberately
    // NOT role-gated: a project owner is any authenticated user, so
    // authorization is explicit project ownership inside ApplicationService.
    // A role check here would be both weaker (it would not prove ownership) and
    // wrong (it would exclude legitimate non-learner owners).
    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::get('/projects/{project}/applications', [ApplicationController::class, 'indexForProject'])
            ->name('projects.applications.index')
            ->whereNumber('project');
        Route::patch('/projects/{project}/applications/{application}', [ApplicationController::class, 'decide'])
            ->name('projects.applications.decide')
            ->whereNumber('project')
            ->whereNumber('application');
    });

    // ──────────────────────────────────────────────────────────────
    // Project Management Workflow (Pilot) — the owner / administrator side
    // of a project: create, update, submit, approve, request changes,
    // reject, open.
    //
    // The `role` middleware accepts a SINGLE slug, so a multi-role rule
    // (owner OR platform admin, or company_admin / university_admin / admin
    // for creation) cannot be expressed on the route. Same precedent as the
    // application owner-side endpoints above: authorization lives in
    // ProjectLifecycleService, which checks the stored owner_id and the
    // actor's roles.
    // ──────────────────────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::post('/projects', [ProjectManagementController::class, 'store'])
            ->name('projects.store');

        Route::patch('/projects/{project}', [ProjectManagementController::class, 'update'])
            ->name('projects.update')
            ->whereNumber('project');

        Route::post('/projects/{project}/submit', [ProjectManagementController::class, 'submit'])
            ->name('projects.submit')
            ->whereNumber('project');

        // Opening is the step that makes an APPROVED project discoverable and
        // lets matching and applications operate.
        Route::post('/projects/{project}/open', [ProjectManagementController::class, 'open'])
            ->name('projects.open')
            ->whereNumber('project');
    });

    // Review decisions are platform-administrator only. The `admin` middleware
    // is the first line of defence; ProjectLifecycleService re-checks, so the
    // rule holds even if a route is ever added without the middleware.
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

    // US-REC-01 — intelligent assistant. Read-only explanation of the
    // learner's OWN stored decisions (readiness, skill gaps, roadmap, next
    // best action, project recommendations). It never writes to
    // authoritative records, and it never generates prose — that is
    // FastAPI's responsibility exclusively.
    Route::middleware(['auth:sanctum', 'account.active', 'role:learner'])->prefix('assistant')->group(function () {
        // The most expensive route on the API: a billed LLM round trip that can
        // take up to a minute. Throttled per learner.
        Route::post('/ask', [AssistantController::class, 'ask'])
            ->middleware('throttle:assistant-ask');

        // §12.6 incident flow / REC-08 — report a response as unsafe,
        // irrelevant, unfair or incorrect.
        Route::put('/interactions/{interaction}/report', [AssistantController::class, 'report']);
    });

    // AI → human support handoff. When the assistant answers with
    // `insufficient_context` (or the learner simply asks for a human), the
    // learner can open a support request. It is NOT a `conversations` row:
    // `conversations.mentor_student_connection_id` is NOT nullable, so a
    // learner with no mentor could never have one.
    Route::middleware(['auth:sanctum', 'account.active', 'role:learner'])
    ->prefix('support')
    ->group(function () {
        // Both writes notify the mentor and the admins, so they share one
        // per-learner budget — generous, because a thread is a conversation.
        Route::post('/requests', [SupportRequestController::class, 'store'])
            ->middleware('throttle:support-write');

        Route::get('/requests', [SupportRequestController::class, 'index']);

        Route::get('/requests/{supportRequest}', [SupportRequestController::class, 'show'])
            ->whereNumber('supportRequest');

        Route::post('/requests/{supportRequest}/messages', [SupportRequestController::class, 'message'])
            ->whereNumber('supportRequest')
            ->middleware('throttle:support-write');

        // The face of whoever answered. Its own route rather than a field on
        // the message payload: the avatar is a blob, and a thread with ten
        // replies would re-send the same photo ten times.
        Route::get('/avatar/{user}', [SupportRequestController::class, 'avatar'])
            ->whereNumber('user');
    });

    // Public reference data for onboarding dropdowns (no auth needed).
    Route::prefix('reference')->group(function () {
        Route::get('/universities', [ReferenceController::class, 'universities']);
        Route::get('/specializations', [ReferenceController::class, 'specializations']);
        Route::get('/countries', [ReferenceController::class, 'countries']);
        Route::get('/countries/{country}/universities', [ReferenceController::class, 'countryUniversities']);
    });

    // Skills API
    Route::middleware(['auth:sanctum', 'account.active'])->prefix('skills')->group(function () {
        Route::get('/taxonomy', [SkillsController::class, 'taxonomy']);
        Route::get('/matrix', [SkillsController::class, 'matrix']);
        Route::post('/matrix', [SkillsController::class, 'store']);
        Route::put('/matrix/{id}', [SkillsController::class, 'update']);
    });

    // Evidence Submission API
    Route::middleware(['auth:sanctum', 'account.active'])->prefix('evidence')->group(function () {
        // Uploading stores a file and triggers a skill recalculation, so it is
        // throttled per learner.
        Route::post('/', [EvidenceController::class, 'store'])
            ->middleware(['role:learner', 'throttle:evidence-upload']);
        Route::get('/', [EvidenceController::class, 'index'])
            ->middleware('role:learner');
        Route::get('/{id}', [EvidenceController::class, 'show']);
        Route::put('/{id}/review', [EvidenceController::class, 'review'])
            ->middleware('role:admin');
    });

    // Career Role Retrieval API
    Route::middleware(['auth:sanctum', 'account.active'])->prefix('career-roles')->group(function () {
        Route::get('/', [CareerRoleController::class, 'index'])
            ->middleware('role:learner');
        Route::get('/{id}', [CareerRoleController::class, 'show'])
            ->middleware('role:learner');
        Route::get('/{id}/skills', [CareerRoleController::class, 'skills'])
            ->middleware('role:learner');
    });

    // Self-service organization APIs. 'organization.approved' is the second,
    // independent line of defense against a pending/rejected organization
    // account reaching protected data — even if it somehow obtains a valid
    // Sanctum token (e.g. by hitting the generic /api/auth/login endpoint).
    Route::middleware(['auth:sanctum', 'account.active', 'organization.approved'])->prefix('organization')->group(function () {
        Route::get('/profile', [OrganizationController::class, 'profile']);
    });

    // ──────────────────────────────────────────────────────────────
    // Mentor–Student communication system.
    // Mentor identity = verified ProfessionalProfile (type=mentor,
    // verification_status=verified). Not a role-slug; the 'mentor'
    // middleware checks the profile table directly.
    // ──────────────────────────────────────────────────────────────

    // Mentor-only endpoints: student discovery, project connections.
    Route::middleware(['auth:sanctum', 'account.active', 'mentor'])->prefix('mentor')->group(function () {
        Route::get('/students', [MentorStudentController::class, 'students']);
        Route::get('/students/{student}', [MentorStudentController::class, 'studentSummary']);
        Route::post('/connections', [MentorStudentController::class, 'connect']);
        Route::get('/connections', [MentorStudentController::class, 'connections']);
        Route::patch('/connections/{connection}', [MentorStudentController::class, 'updateConnection']);
    });

    // Conversation endpoints: both mentors and students access these.
    // Authorization (participant check) happens in ConversationService,
    // not at the middleware layer.
    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::post('/connections/{connection}/conversations', [ConversationController::class, 'store']);
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'sendMessage']);
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markAsRead']);
        Route::get('/conversations/{conversation}/status', [ConversationController::class, 'status']);

        // Chatbot communication endpoints — automated messages tagged
        // message_type=chatbot, participant-authorized like human messages.
        Route::post('/conversations/{conversation}/chatbot/messages', [ChatbotController::class, 'send']);
        Route::get('/conversations/{conversation}/chatbot/messages', [ChatbotController::class, 'messages']);
    });

    // Relevant notifications — the caller's own in-app notification feed
    // plus per-category/channel delivery preferences.
    Route::middleware(['auth:sanctum', 'account.active'])->prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::get('/preferences', [NotificationController::class, 'preferences']);
        Route::put('/preferences', [NotificationController::class, 'updatePreference']);
        Route::post('/{notification}/read', [NotificationController::class, 'markAsRead']);
    });

    Route::post('/setup/create-admin', [SetupController::class, 'createAdmin'])
        ->middleware('throttle:5,1');

    Route::post('/setup/create-mentor', [SetupController::class, 'createMentor'])
        ->middleware('throttle:10,1');

    // Server-to-server: called by the Data Science FastAPI service, not by
    // logged-in users. Auth is a shared secret checked inside the
    // controller (X-Internal-Secret), not Sanctum.
    Route::prefix('internal')->group(function () {
        Route::get('/baseline-items', [BaselineItemsController::class, 'index'])
            ->middleware('throttle:60,1');
    });
});
