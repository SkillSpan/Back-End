<?php

use App\Http\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BaselineAssessmentController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\Internal\BaselineItemsController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReadinessController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\SetupController;
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

    // US-AUTH-06: logout requires a valid session, unlike the routes above.
    Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    });

    Route::middleware(['auth:sanctum', 'role:learner'])->prefix('profile')->group(function () {
        Route::post('/', [ProfileController::class, 'store']);
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
    });

    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
        Route::get('/organizations', [AdminOrganizationController::class, 'index']);
        Route::get('/organizations/{organization}', [AdminOrganizationController::class, 'show']);
        Route::get('/organizations/{organization}/proof-file', [AdminOrganizationController::class, 'downloadProofFile'])
            ->name('admin.organizations.proof-file');
        Route::post('/organizations/{organization}/approve', [AdminOrganizationController::class, 'approve']);
        Route::post('/organizations/{organization}/reject', [AdminOrganizationController::class, 'reject']);
    });

    Route::middleware(['auth:sanctum', 'role:learner'])->group(function () {
        Route::post('/readiness/calculate', [ReadinessController::class, 'calculate']);
        Route::get('/readiness/latest', [ReadinessController::class, 'latest']);
    });

    Route::middleware(['auth:sanctum', 'role:learner'])->group(function () {
        Route::post('/baseline-assessments', [BaselineAssessmentController::class, 'start']);
        Route::get('/baseline-assessments/{assessment}', [BaselineAssessmentController::class, 'show']);
        Route::patch('/baseline-assessments/{assessment}', [BaselineAssessmentController::class, 'progress']);
        Route::post('/baseline-assessments/{assessment}/submit', [BaselineAssessmentController::class, 'submit']);
    });

    // Public reference data for onboarding dropdowns (no auth needed).
    Route::prefix('reference')->group(function () {
        Route::get('/universities', [ReferenceController::class, 'universities']);
        Route::get('/specializations', [ReferenceController::class, 'specializations']);
        Route::get('/countries', [ReferenceController::class, 'countries']);
        Route::get('/countries/{country}/universities', [ReferenceController::class, 'countryUniversities']);
    });

    // Skills API
    Route::middleware('auth:sanctum')->prefix('skills')->group(function () {
        Route::get('/taxonomy', [SkillsController::class, 'taxonomy']);
        Route::get('/matrix', [SkillsController::class, 'matrix']);
        Route::post('/matrix', [SkillsController::class, 'store']);
        Route::put('/matrix/{id}', [SkillsController::class, 'update']);
    });

    // Evidence Submission API
    Route::middleware('auth:sanctum')->prefix('evidence')->group(function () {
        Route::post('/', [EvidenceController::class, 'store'])
            ->middleware('role:learner');
        Route::get('/', [EvidenceController::class, 'index'])
            ->middleware('role:learner');
        Route::get('/{id}', [EvidenceController::class, 'show']);
        Route::put('/{id}/review', [EvidenceController::class, 'review'])
            ->middleware('role:admin');
    });

    // Self-service organization APIs. 'organization.approved' is the second,
    // independent line of defense against a pending/rejected organization
    // account reaching protected data — even if it somehow obtains a valid
    // Sanctum token (e.g. by hitting the generic /api/auth/login endpoint).
    Route::middleware(['auth:sanctum', 'organization.approved'])->prefix('organization')->group(function () {
        Route::get('/profile', [OrganizationController::class, 'profile']);
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
