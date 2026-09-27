<?php

use App\Http\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
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
use App\Http\Controllers\Api\ReadinessController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\SkillMatchController;
use App\Http\Controllers\Api\SkillsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/register/organization', [AuthController::class, 'registerOrganization'])->middleware('throttle:10,1');
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
    });

    Route::middleware(['auth:sanctum', 'account.active', 'role:learner'])->group(function () {
        Route::post('/readiness/calculate', [ReadinessController::class, 'calculate']);
        Route::get('/readiness/latest', [ReadinessController::class, 'latest']);
        Route::post('/skill-match', [SkillMatchController::class, 'store']);

        // US-INT-01 — intelligence decision endpoints (skill gap +
        // readiness + roadmap in one atomic decision).
        Route::post('/intelligence/calculate', [IntelligenceController::class, 'calculate']);
        Route::get('/intelligence/latest', [IntelligenceController::class, 'latest']);
    });

    Route::middleware(['auth:sanctum', 'account.active', 'role:learner'])->group(function () {
        Route::post('/baseline-assessments', [BaselineAssessmentController::class, 'start']);
        Route::get('/baseline-assessments/{assessment}', [BaselineAssessmentController::class, 'show']);
        Route::patch('/baseline-assessments/{assessment}', [BaselineAssessmentController::class, 'progress']);
        Route::post('/baseline-assessments/{assessment}/submit', [BaselineAssessmentController::class, 'submit']);
    });

    // US-REC-01 — intelligent assistant. Read-only explanation of the
    // learner's OWN stored decisions (readiness, skill gaps, roadmap, next
    // best action, project recommendations). It never writes to
    // authoritative records, and it never generates prose — that is
    // FastAPI's responsibility exclusively.
    Route::middleware(['auth:sanctum', 'account.active', 'role:learner'])->prefix('assistant')->group(function () {
        Route::post('/ask', [AssistantController::class, 'ask']);

        // §12.6 incident flow / REC-08 — report a response as unsafe,
        // irrelevant, unfair or incorrect.
        Route::put('/interactions/{interaction}/report', [AssistantController::class, 'report']);
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
        Route::post('/', [EvidenceController::class, 'store'])
            ->middleware('role:learner');
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

    // Server-to-server: called by the Data Science FastAPI service, not by
    // logged-in users. Auth is a shared secret checked inside the
    // controller (X-Internal-Secret), not Sanctum.
    Route::prefix('internal')->group(function () {
        Route::get('/baseline-items', [BaselineItemsController::class, 'index'])
            ->middleware('throttle:60,1');
    });
});
