<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SkillBridge API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/register/organization', [AuthController::class, 'registerOrganization']);
    Route::post('/verify', [AuthController::class, 'verify']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/forgot-password/resend', [AuthController::class, 'resendPasswordReset']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});