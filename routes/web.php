<?php

use App\Http\Controllers\Web\AdminAuthController;
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
     */
    Route::view('/projects', 'admin.projects')->name('admin.projects');

    Route::get('/api/projects', [AdminProjectController::class, 'index']);
    Route::post('/api/projects', [AdminProjectController::class, 'store']);

    // Session-backed reference data for the Project form.
    Route::get('/api/projects/career-roles', [AdminProjectController::class, 'careerRoles']);
    Route::get('/api/projects/career-roles/{careerRole}/skills', [AdminProjectController::class, 'careerRoleSkills'])
        ->whereNumber('careerRole');
    Route::get('/api/projects/{project}', [AdminProjectController::class, 'show'])
        ->whereNumber('project');
    Route::patch('/api/projects/{project}', [AdminProjectController::class, 'update'])
        ->whereNumber('project');
    Route::post('/api/projects/{project}/cancel', [AdminProjectController::class, 'cancel'])
        ->whereNumber('project');

    /*
     * Project lifecycle actions for the panel's detail overlay.
     *
     * These used to be called straight on /api/v1/projects/{id}/{action} on the
     * theory that the transitions would then stay defined in exactly one place.
     * The theory was wrong about the guard: /api/v1 sits behind `auth:sanctum`,
     * which reads a bearer token, while the panel holds a session cookie — so
     * every press of Submit / Approve / Request changes / Reject / Open came
     * back 401, and the panel's api() helper answered a 401 by navigating to
     * /admin/login. The operator was thrown out of the page instead of moving
     * the project.
     *
     * The transitions still live in exactly one place: these routes point at a
     * session twin that only re-declares ProjectManagementController on the web
     * guard, and all authorization remains in ProjectLifecycleService, which
     * reads the stored owner_id and the actor's roles rather than the guard.
     */
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

    /*
     * Skills reference data for the project form's skill picker.
     *
     * The canonical endpoint is `/api/v1/skills/taxonomy`, but that sits behind
     * `auth:sanctum` and therefore needs a bearer token. The panel has a session
     * cookie, not a token, so calling it directly returned 401 and the picker
     * came up empty. This session-backed twin serves the same payload.
     */
    Route::get('/api/skills/taxonomy', [SkillsReferenceController::class, 'taxonomy']);

    /*
     * Dynamic assessment question bank — the admin "Questions" page.
     *
     * The page drives the existing chain
     * specialization -> career role -> skill -> question, using the models
     * and relationships already in the project (Specialization::careerRoles(),
     * CareerRole::roleSkills(), Skill, baseline_assessment_items). Nothing
     * new is modelled: the endpoints below read and write the same item bank
     * the baseline assessment selects from.
     *
     * The page itself plus the session-authenticated JSON endpoints its
     * JavaScript calls, mirroring the projects panel above.
     */
    Route::view('/questions', 'admin.questions')->name('admin.questions');

    Route::get('/api/questions', [AdminQuestionController::class, 'index']);

    // Dynamic dropdowns. Ordered before the {question} routes so a literal
    // segment can never be mistaken for a bound model id.
    Route::get('/api/questions/specializations', [AdminQuestionController::class, 'specializations']);
    Route::get('/api/questions/career-roles', [AdminQuestionController::class, 'careerRoles']);
    Route::get('/api/questions/skills', [AdminQuestionController::class, 'skills']);
    Route::get('/api/questions/skill-context', [AdminQuestionController::class, 'skillContext']);

    Route::post('/api/questions', [AdminQuestionController::class, 'store']);
    Route::patch('/api/questions/{question}', [AdminQuestionController::class, 'update'])
        ->whereNumber('question');
    Route::delete('/api/questions/{question}', [AdminQuestionController::class, 'destroy'])
        ->whereNumber('question');

    /*
     * Specialization management — the admin "Specializations" page.
     *
     * The page lists specializations and lets an operator link each one to
     * its career roles through the EXISTING career_role_specialization
     * pivot. Deleting a specialization that is still in use is refused; the
     * panel offers Deactivate instead.
     */
    Route::view('/specializations', 'admin.specializations')->name('admin.specializations');

    Route::get('/api/specializations', [AdminSpecializationController::class, 'index']);

    // Literal segments before the {specialization} routes so a literal can
    // never be mistaken for a bound model id.
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
| The profile block further down shares this group for the same reason: the
| audience is identical, so the two must not be allowed to drift apart.
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

/*
|--------------------------------------------------------------------------
| Panel profile — administrator AND mentor
|--------------------------------------------------------------------------
|
| The account's own name, title, bio, age, photo and password. It lives in
| this group rather than the `admin` one above for the same reason the inbox
| does: mentors open the panel, and locking them out of their own profile
| would leave them unable to change a password the seeder chose.
|
| Api\Admin\ProfileController enforces the audience — anyone who is neither an
| administrator nor a mentor gets 403. There is no id anywhere in these routes:
| every method reads the authenticated user, so no request shape edits somebody
| else's profile.
|
| The avatar is its own route rather than a field in the JSON, so the browser
| can cache the image. Its `?v=` token changes whenever the bytes do, which is
| what makes the year-long cache header safe.
|
*/
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
