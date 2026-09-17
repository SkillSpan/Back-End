<?php

use App\Http\Controllers\Web\AdminAuthController;
use App\Http\Controllers\Web\AdminOrganizationController;
use App\Http\Controllers\Web\TestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

if (app()->environment('local')) {
    Route::get('/test-register', [TestController::class, 'showRegisterForm'])->name('test.register');
}

/*
|--------------------------------------------------------------------------
| Admin panel sign-in
|--------------------------------------------------------------------------
|
| Named 'login' so Laravel's "auth" middleware knows where to send guests
| who ask for a protected admin page.
|
*/
Route::middleware('guest')->group(function () {
    Route::get('/admin/login', [AdminAuthController::class, 'showLoginForm'])->name('login');
    Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login');
});

Route::post('/admin/logout', [AdminAuthController::class, 'logout'])
    ->middleware('auth')
    ->name('admin.logout');

/*
|--------------------------------------------------------------------------
| Admin review panel
|--------------------------------------------------------------------------
|
| The page itself plus the session-authenticated JSON endpoints its
| JavaScript calls. These used to be the token-protected /api/v1/admin/*
| routes; they now sit on the normal web session so the panel no longer
| needs a manually pasted Bearer token. The 'admin' middleware still
| refuses any account that does not hold the admin role.
|
*/
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::view('/organizations', 'admin.organizations')->name('admin.organizations');

    Route::get('/api/organizations', [AdminOrganizationController::class, 'index']);
    Route::get('/api/organizations/{organization}', [AdminOrganizationController::class, 'show']);
    Route::post('/api/organizations/{organization}/approve', [AdminOrganizationController::class, 'approve']);
    Route::post('/api/organizations/{organization}/reject', [AdminOrganizationController::class, 'reject']);

    Route::get('/organizations/{organization}/proof-file', [AdminOrganizationController::class, 'downloadProofFile'])
        ->name('admin.web.organizations.proof-file');
});
