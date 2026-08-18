<?php

use App\Http\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ReadinessController;
use App\Http\Controllers\Api\SetupController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/register/organization', [AuthController::class, 'registerOrganization']);
    Route::post('/verify', [AuthController::class, 'verify']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/login/organization', [AuthController::class, 'loginOrganization']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/forgot-password/resend', [AuthController::class, 'resendPasswordReset']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/organizations', [AdminOrganizationController::class, 'index']);
    Route::get('/organizations/{organization}', [AdminOrganizationController::class, 'show']);
    Route::post('/organizations/{organization}/approve', [AdminOrganizationController::class, 'approve']);
    Route::post('/organizations/{organization}/reject', [AdminOrganizationController::class, 'reject']);
});

Route::middleware(['auth:sanctum', 'role:learner'])->group(function () {
    Route::post('/readiness/calculate', [ReadinessController::class, 'calculate']);
    Route::get('/readiness/latest', [ReadinessController::class, 'latest']);
});

Route::post('/setup/create-admin', [SetupController::class, 'createAdmin']);
