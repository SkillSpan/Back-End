<?php

use App\Http\Controllers\Web\AdminAuthController;
use App\Http\Controllers\Web\AdminCareerRoleController;
use App\Http\Controllers\Web\AdminOrganizationController;
use App\Http\Controllers\Web\AdminProfileController;
use App\Http\Controllers\Web\AdminProjectController;
use App\Http\Controllers\Web\AdminProjectLifecycleController;
use App\Http\Controllers\Web\AdminQuestionController;
use App\Http\Controllers\Web\AdminSpecializationController;
use App\Http\Controllers\Web\AdminSupportController;
use App\Http\Controllers\Web\SkillsReferenceController;
use App\Http\Controllers\Web\TestController;
use App\Support\PanelAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

if (app()->environment('local')) {
    Route::get('/test-register', [TestController::class, 'showRegisterForm'])->name('test.register');
}

Route::middleware('guest')->group(function () {
    Route::get('/admin/login', [AdminAuthController::class, 'showLoginForm'])->name('login');
    Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login');
});

Route::post('/admin/logout', [AdminAuthController::class, 'logout'])
    ->middleware('auth')
    ->name('admin.logout');

Route::middleware(['auth', 'account.active', 'admin'])->prefix('admin')->group(function () {
    Route::view('/organizations', 'admin.organizations')->name('admin.organizations');

    Route::get('/api/organizations', [AdminOrganizationController::class, 'index']);
    Route::get('/api/organizations/{organization}', [AdminOrganizationController::class, 'show']);
    Route::post('/api/organizations/{organization}/approve', [AdminOrganizationController::class, 'approve']);
    Route::post('/api/organizations/{organization}/reject', [AdminOrganizationController::class, 'reject']);

    Route::get('/organizations/{organization}/proof-file', [AdminOrganizationController::class, 'downloadProofFile'])
        ->name('admin.web.organizations.proof-file');

    Route::view('/projects', 'admin.projects')->name('admin.projects');

    Route::get('/api/projects', [AdminProjectController::class, 'index']);
    Route::post('/api/projects', [AdminProjectController::class, 'store']);

    Route::get('/api/projects/career-roles', [AdminProjectController::class, 'careerRoles']);
    Route::get('/api/projects/career-roles/{careerRole}/skills', [AdminProjectController::class, 'careerRoleSkills'])
        ->whereNumber('careerRole');
    Route::get('/api/projects/{project}', [AdminProjectController::class, 'show'])
        ->whereNumber('project');
    Route::patch('/api/projects/{project}', [AdminProjectController::class, 'update'])
        ->whereNumber('project');
    Route::post('/api/projects/{project}/cancel', [AdminProjectController::class, 'cancel'])
        ->whereNumber('project');

    Route::post('/api/projects/{project}/submit', [AdminProjectLifecycleController::class, 'submit'])
        ->whereNumber('project');
    Route::post('/api/projects/{project}/approve', [AdminProjectLifecycleController::class, 'approve'])
        ->whereNumber('project');
    Route::post('/api/projects/{project}/request-changes', [AdminProjectLifecycleController::class, 'requestChanges'])
        ->whereNumber('project');
    Route::post('/api/projects/{project}/reject', [AdminProjectLifecycleController::class, 'reject'])
        ->whereNumber('project');
    Route::post('/api/projects/{project}/open', [AdminProjectLifecycleController::class, 'open'])
        ->whereNumber('project');

    Route::get('/api/skills/taxonomy', [SkillsReferenceController::class, 'taxonomy']);

    Route::view('/questions', 'admin.questions')->name('admin.questions');

    Route::get('/api/questions', [AdminQuestionController::class, 'index']);

    Route::get('/api/questions/specializations', [AdminQuestionController::class, 'specializations']);
    Route::get('/api/questions/career-roles', [AdminQuestionController::class, 'careerRoles']);
    Route::get('/api/questions/skills', [AdminQuestionController::class, 'skills']);
    Route::get('/api/questions/skill-context', [AdminQuestionController::class, 'skillContext']);

    Route::post('/api/questions', [AdminQuestionController::class, 'store']);
    Route::patch('/api/questions/{question}', [AdminQuestionController::class, 'update'])
        ->whereNumber('question');
    Route::delete('/api/questions/{question}', [AdminQuestionController::class, 'destroy'])
        ->whereNumber('question');

    Route::view('/specializations', 'admin.specializations')->name('admin.specializations');

    Route::get('/api/specializations', [AdminSpecializationController::class, 'index']);

    Route::get('/api/specializations/skills', [AdminSpecializationController::class, 'skills']);
    Route::post('/api/specializations/career-roles', [AdminSpecializationController::class, 'storeCareerRole']);
    Route::post('/api/specializations', [AdminSpecializationController::class, 'store']);

    Route::get('/api/specializations/{specialization}/career-roles', [AdminSpecializationController::class, 'careerRoles'])
        ->whereNumber('specialization');
    Route::put('/api/specializations/{specialization}/career-roles', [AdminSpecializationController::class, 'syncCareerRoles'])
        ->whereNumber('specialization');
    Route::patch('/api/specializations/{specialization}', [AdminSpecializationController::class, 'update'])
        ->whereNumber('specialization');
    Route::post('/api/specializations/{specialization}/activate', [AdminSpecializationController::class, 'activate'])
        ->whereNumber('specialization');
    Route::post('/api/specializations/{specialization}/deactivate', [AdminSpecializationController::class, 'deactivate'])
        ->whereNumber('specialization');
    Route::delete('/api/specializations/{specialization}', [AdminSpecializationController::class, 'destroy'])
        ->whereNumber('specialization');

    Route::get('/api/career-roles', [AdminCareerRoleController::class, 'index']);
    Route::post('/api/career-roles/{careerRole}/activate', [AdminCareerRoleController::class, 'activate'])
        ->whereNumber('careerRole');
    Route::post('/api/career-roles/{careerRole}/deactivate', [AdminCareerRoleController::class, 'deactivate'])
        ->whereNumber('careerRole');
});

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

    Route::get('/support/avatar/{user}', [AdminSupportController::class, 'avatar'])
        ->whereNumber('user')
        ->name('admin.support.avatar');

    Route::delete('/api/support/{supportRequest}/messages/{message}', [AdminSupportController::class, 'destroyMessage'])
        ->whereNumber('supportRequest')
        ->whereNumber('message');

    Route::delete('/api/support/{supportRequest}', [AdminSupportController::class, 'destroy'])
        ->whereNumber('supportRequest');
});

Route::middleware(['auth', 'account.active'])->prefix('admin')->group(function () {
    Route::get('/profile', function (Request $request) {
        abort_unless(PanelAccess::allows($request->user()), 403);

        return view('admin.profile');
    })->name('admin.profile');

    Route::get('/profile/avatar', [AdminProfileController::class, 'avatar'])
        ->name('admin.profile.avatar');

    Route::get('/api/profile', [AdminProfileController::class, 'show']);

    Route::patch('/api/profile', [AdminProfileController::class, 'updateDetails']);

    Route::post('/api/profile/avatar', [AdminProfileController::class, 'uploadAvatar']);

    Route::delete('/api/profile/avatar', [AdminProfileController::class, 'deleteAvatar']);

    Route::post('/api/profile/password', [AdminProfileController::class, 'changePassword']);
});
