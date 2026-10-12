<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserIsMentor;
use App\Models\MentorStudentConnection;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Communication\MentorStudentTest;
use Tests\TestCase;

/**
 * BA-05 — the Mentor API authorization matrix, closed over EVERY mentor route.
 *
 * The mentor surface is five routes and they all share one middleware group:
 *
 *     auth:sanctum  →  account.active  →  mentor
 *
 * `mentor` is NOT a role check. A mentor is a user whose ProfessionalProfile
 * has type='mentor' AND verification_status='verified' (see
 * App\Http\Middleware\EnsureUserIsMentor), because mentor status is a
 * professional-profile attribute with its own verification lifecycle, not a
 * role slug. The only way to obtain that profile is the shared-secret
 * /setup/create-mentor endpoint, which is itself secret-gated, kill-switchable,
 * throttled and refuses to touch an administrator account.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The pre-existing coverage asserted the refusals on THREE of the five routes
 * and only for a learner. Two things were therefore unproven:
 *
 *   1. `GET /mentor/students/{student}` and `PATCH /mentor/connections/{id}`
 *      were never checked against a non-mentor caller — a route added to the
 *      group without the middleware would have gone unnoticed.
 *   2. No NON-LEARNER role was ever tried. The brief asks specifically about
 *      "an authenticated user with another role", and a **platform
 *      administrator** is the case that matters most: `admin` is the highest
 *      role on the platform, and it must NOT imply mentor access.
 *
 * Every test below iterates the whole route list, so adding a sixth mentor
 * route without protecting it fails here rather than in production.
 *
 * @see EnsureUserIsMentor
 * @see EnsureAccountIsActive
 * @see MentorStudentTest
 */
class MentorRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Roles that are NOT mentors — including the platform administrator. */
    private const NON_MENTOR_ROLES = ['learner', 'company_admin', 'university_admin', 'admin'];

    private const SETUP_SECRET = 'ba05-mentor-setup-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mentor_setup.secret' => self::SETUP_SECRET]);

        foreach ([
            ['Learner', 'learner'],
            ['Company Admin', 'company_admin'],
            ['University Admin', 'university_admin'],
            ['Admin', 'admin'],
        ] as [$name, $slug]) {
            Role::create(['name' => $name, 'slug' => $slug, 'description' => '']);
        }
    }

    // -----------------------------------------------------------------
    // The matrix
    // -----------------------------------------------------------------

    /**
     * Every mentor route, with the ids deliberately pointing at rows that do
     * NOT exist. That is the point: if the middleware were missing, these would
     * answer 404 (no such student/connection) instead of 401/403, so a passing
     * status assertion proves the gate ran BEFORE the controller.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function mentorRoutes(): array
    {
        return [
            'GET /mentor/students' => ['GET', '/api/v1/mentor/students', []],
            'GET /mentor/students/{student}' => ['GET', '/api/v1/mentor/students/999999', []],
            'POST /mentor/connections' => ['POST', '/api/v1/mentor/connections', []],
            'GET /mentor/connections' => ['GET', '/api/v1/mentor/connections', []],
            'PATCH /mentor/connections/{connection}' => ['PATCH', '/api/v1/mentor/connections/999999', ['status' => 'active']],
        ];
    }

    public function test_every_mentor_route_returns_401_to_an_unauthenticated_caller(): void
    {
        foreach ($this->mentorRoutes() as $label => [$method, $path, $payload]) {
            $response = $this->json($method, $path, $payload);

            $this->assertSame(401, $response->getStatusCode(), "{$label} did not require authentication.");
        }
    }

    /**
     * The core of BA-05. A learner is the obvious case; a platform
     * administrator is the dangerous one — `admin` must not imply mentor.
     */
    public function test_no_non_mentor_role_can_reach_any_mentor_route(): void
    {
        foreach (self::NON_MENTOR_ROLES as $role) {
            foreach ($this->mentorRoutes() as $label => [$method, $path, $payload]) {
                Sanctum::actingAs($this->userWithRole($role));

                $response = $this->json($method, $path, $payload);

                $this->assertSame(
                    403,
                    $response->getStatusCode(),
                    "A '{$role}' was not refused on {$label}.",
                );

                $response->assertJsonPath('code', 'MENTOR_ONLY');
            }
        }
    }

    public function test_an_unverified_mentor_profile_is_refused_on_every_route(): void
    {
        Sanctum::actingAs($this->userWithRole('learner', profileStatus: 'pending'));

        foreach ($this->mentorRoutes() as $label => [$method, $path, $payload]) {
            $response = $this->json($method, $path, $payload);

            $this->assertSame(403, $response->getStatusCode(), "{$label} let an UNVERIFIED mentor through.");
            $response->assertJsonPath('code', 'MENTOR_ONLY');
        }
    }

    /**
     * A suspended account is stopped by `account.active`, which runs BEFORE
     * `mentor` — so the code is ACCOUNT_DISABLED, not MENTOR_ONLY. Asserting
     * the exact code pins the middleware ORDER, not just the status.
     */
    public function test_a_suspended_mentor_is_refused_on_every_route(): void
    {
        $mentor = $this->userWithRole('learner', profileStatus: 'verified');
        $mentor->forceFill(['status' => 'suspended'])->save();

        Sanctum::actingAs($mentor);

        foreach ($this->mentorRoutes() as $label => [$method, $path, $payload]) {
            $response = $this->json($method, $path, $payload);

            $this->assertSame(403, $response->getStatusCode(), "{$label} let a SUSPENDED mentor through.");
            $response->assertJsonPath('code', 'ACCOUNT_DISABLED');
        }
    }

    /**
     * The positive half: a genuinely verified mentor is admitted to all five.
     * Without this, every test above would also pass on a route that refuses
     * EVERYONE — a 403-only suite cannot tell "correctly locked" from
     * "permanently broken".
     */
    public function test_a_verified_mentor_reaches_every_mentor_route(): void
    {
        $mentor = $this->userWithRole('learner', profileStatus: 'verified');
        $student = $this->student();
        $connection = MentorStudentConnection::forceCreate([
            'mentor_id' => $mentor->id,
            'student_id' => $student->id,
            'status' => 'pending',
            'initiated_by' => 'mentor',
        ]);

        Sanctum::actingAs($mentor);

        $this->getJson('/api/v1/mentor/students')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->getJson("/api/v1/mentor/students/{$student->id}")
            ->assertStatus(200);

        $this->getJson('/api/v1/mentor/connections')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->patchJson("/api/v1/mentor/connections/{$connection->id}", ['status' => 'active'])
            ->assertStatus(200);

        $this->postJson('/api/v1/mentor/connections', ['student_id' => $this->student()->id])
            ->assertStatus(201);
    }

    /**
     * Mentor identity is a PROFILE attribute, so an administrator who also
     * holds a verified mentor profile is admitted — deliberately. Pinned so
     * that nobody "hardens" this by bolting a role check onto the middleware,
     * which would lock out legitimate mentor-administrators.
     */
    public function test_an_administrator_with_a_verified_mentor_profile_is_admitted(): void
    {
        Sanctum::actingAs($this->userWithRole('admin', profileStatus: 'verified'));

        $this->getJson('/api/v1/mentor/students')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * The gate must not LEAK the other way: mentor status is not a
     * superpower. A verified mentor profile adds nothing on a learner-only
     * route — the caller here is a company_admin, so a 200 would mean the
     * mentor profile had widened their access.
     */
    public function test_mentor_status_grants_no_access_to_learner_routes(): void
    {
        Sanctum::actingAs($this->userWithRole('company_admin', profileStatus: 'verified'));

        $this->getJson('/api/v1/projects')->assertStatus(403);
        $this->getJson('/api/v1/recommendations')->assertStatus(403);
    }

    /**
     * The setup endpoint that MINTS mentor status is not a way in: the shared
     * secret is configured here, and a wrong one is refused — so this cannot
     * be used to self-promote into the mentor surface.
     */
    public function test_the_mentor_setup_endpoint_cannot_be_used_to_self_promote(): void
    {
        $this->postJson('/api/v1/setup/create-mentor', [
            'secret' => 'not-the-secret',
            'email' => 'attacker@test.com',
            'password' => 'password123',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'attacker@test.com']);
        $this->assertDatabaseMissing('professional_profiles', ['type' => 'mentor']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * An active user holding one role, optionally with a mentor profile in the
     * given verification state.
     */
    private function userWithRole(string $role, ?string $profileStatus = null): User
    {
        $user = User::forceCreate([
            'name' => 'BA05 '.$role,
            'email' => 'ba05_'.$role.'_'.uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', $role)->first()->id);

        if ($profileStatus !== null) {
            ProfessionalProfile::forceCreate([
                'user_id' => $user->id,
                'type' => 'mentor',
                'verification_status' => $profileStatus,
            ]);
        }

        return $user;
    }

    private function student(string $visibility = 'public'): User
    {
        $user = User::forceCreate([
            'name' => 'BA05 Student',
            'email' => 'ba05_student_'.uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => $visibility,
            'university_name' => 'King Saud University',
            'specialization' => 'Computer Science',
            'career_status' => 'Student',
            'preferred_work_type' => 'remote',
            'availability' => 'full_time',
            'interests' => ['AI'],
        ]);

        return $user;
    }
}
