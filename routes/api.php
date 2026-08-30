<?php

use App\Http\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReadinessController;
use App\Http\Controllers\Api\SetupController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/register/organization', [AuthController::class, 'registerOrganization']);
        Route::post('/verify', [AuthController::class, 'verify']);
        Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/login/google', [AuthController::class, 'loginWithGoogle']);
        Route::post('/login/organization', [AuthController::class, 'loginOrganization']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/forgot-password/resend', [AuthController::class, 'resendPasswordReset']);
        Route::post('/forgot-password/verify', [AuthController::class, 'verifyPasswordReset']);
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

    // Self-service organization APIs. 'organization.approved' is the second,
    // independent line of defense against a pending/rejected organization
    // account reaching protected data — even if it somehow obtains a valid
    // Sanctum token (e.g. by hitting the generic /api/auth/login endpoint).
    Route::middleware(['auth:sanctum', 'organization.approved'])->prefix('organization')->group(function () {
        Route::get('/profile', [OrganizationController::class, 'profile']);
    });

    Route::post('/setup/create-admin', [SetupController::class, 'createAdmin'])
        ->middleware('throttle:5,1');
});
