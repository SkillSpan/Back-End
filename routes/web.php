<?php

use App\Http\Controllers\Web\AdminAuthController;
use App\Http\Controllers\Web\AdminOrganizationController;
use App\Http\Controllers\Web\AdminProjectController;
use App\Http\Controllers\Web\AdminSupportController;
use App\Http\Controllers\Web\SkillsReferenceController;
use App\Http\Controllers\Web\TestController;
use Illuminate\Http\Request;
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
| needs a manually pasted Bearer token. 'admin' still refuses any account
| without the admin role, and 'account.active' ends the session if the
| account is suspended after signing in.
|
*/
Route::middleware(['auth', 'account.active', 'admin'])->prefix('admin')->group(function () {
    Route::view('/organizations', 'admin.organizations')->name('admin.organizations');

    Route::get('/api/organizations', [AdminOrganizationController::class, 'index']);
    Route::get('/api/organizations/{organization}', [AdminOrganizationController::class, 'show']);
    Route::post('/api/organizations/{organization}/approve', [AdminOrganizationController::class, 'approve']);
    Route::post('/api/organizations/{organization}/reject', [AdminOrganizationController::class, 'reject']);

    Route::get('/organizations/{organization}/proof-file', [AdminOrganizationController::class, 'downloadProofFile'])
        ->name('admin.web.organizations.proof-file');

    /*
     * Project management. The page plus the session-authenticated JSON
     * endpoints its JavaScript calls, mirroring the organizations panel above.
     *
     * The lifecycle actions (submit / approve / request-changes / reject /
     * open) are deliberately NOT duplicated here: the panel calls the
     * existing /api/v1/projects/* endpoints, so the allowed transitions stay
     * defined in exactly one place.
     */
    Route::view('/projects', 'admin.projects')->name('admin.projects');

    Route::get('/api/projects', [AdminProjectController::class, 'index']);
    Route::post('/api/projects', [AdminProjectController::class, 'store']);
    Route::get('/api/projects/{project}', [AdminProjectController::class, 'show'])
        ->whereNumber('project');
    Route::patch('/api/projects/{project}', [AdminProjectController::class, 'update'])
        ->whereNumber('project');
    Route::post('/api/projects/{project}/cancel', [AdminProjectController::class, 'cancel'])
        ->whereNumber('project');

    /*
     * Skills reference data for the project form's skill picker.
     *
     * The canonical endpoint is `/api/v1/skills/taxonomy`, but that sits behind
     * `auth:sanctum` and therefore needs a bearer token. The panel has a session
     * cookie, not a token, so calling it directly returned 401 and the picker
     * came up empty. This session-backed twin serves the same payload.
     */
    Route::get('/api/skills/taxonomy', [SkillsReferenceController::class, 'taxonomy']);
});

/*
|--------------------------------------------------------------------------
| Support inbox — administrator AND mentor
|--------------------------------------------------------------------------
|
| Where an escalated assistant conversation is answered. When the assistant
| answers `insufficient_context`, the learner is offered a person; accepting
| opens a support request here.
|
| This block is deliberately NOT inside the `admin` group above. The person
| who answers is the mentor already connected to the learner, so gating it on
| `admin` would notify a mentor and then refuse them the very page the
| notification links to. The route carries `auth` + `account.active` only;
| Api\Admin\SupportController enforces the split — an administrator sees every
| request, a mentor only the ones assigned to them, anyone else 403.
|
*/
Route::middleware(['auth', 'account.active'])->prefix('admin')->group(function () {
    Route::get('/support', function (Request $request) {
        abort_unless(AdminSupportController::isSupportAgent($request->user()), 403);

        return view('admin.support');
    })->name('admin.support');

    Route::get('/api/support', [AdminSupportController::class, 'index']);

    Route::get('/api/support/{supportRequest}', [AdminSupportController::class, 'show'])
        ->whereNumber('supportRequest');

    Route::post('/api/support/{supportRequest}/messages', [AdminSupportController::class, 'reply'])
        ->whereNumber('supportRequest');

    Route::post('/api/support/{supportRequest}/assign', [AdminSupportController::class, 'assign'])
        ->whereNumber('supportRequest');

    Route::post('/api/support/{supportRequest}/resolve', [AdminSupportController::class, 'resolve'])
        ->whereNumber('supportRequest');
});
