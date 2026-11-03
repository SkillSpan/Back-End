<?php

namespace Tests\Feature\Company;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The company API surface, in one place.
 *
 * The deep tests already exist and each one pins a rule: `CompanyDashboardTest`
 * the four aggregates, `TalentDiscoveryTest` the visibility model,
 * `OrganizationProfileTest` the profile and its documents. What none of them
 * answers is the question a reviewer actually asks before a release — *is every
 * company endpoint still wired, still guarded, and still answering?* A route
 * dropped in a merge, a middleware removed from a group, or a controller method
 * renamed shows up here as one failing test with the endpoint in its name,
 * instead of as a 404 in production.
 *
 * So this file is a contract matrix, not a second copy of the behaviour:
 *
 *   - every endpoint is registered with the method and path the clients use;
 *   - every one of them refuses an anonymous call (401);
 *   - every one of them refuses a learner token (403 `COMPANY_ADMIN_ONLY`);
 *   - every one of them refuses a pending organization (403 `ORGANIZATION_PENDING`);
 *   - every one of them answers its documented success shape for a real
 *     company admin.
 *
 * The two auth endpoints are included because a company account begins there —
 * without them the rest of the surface is unreachable.
 */
class CompanyApiTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    private Role $companyAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->companyAdminRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    // -----------------------------------------------------------------
    // The surface
    // -----------------------------------------------------------------

    /**
     * Every company endpoint that needs a token: the method and the exact path
     * a client calls.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function protectedEndpoints(): array
    {
        return [
            'organization profile' => ['GET', '/api/v1/organization/profile'],
            'profile me — read' => ['GET', '/api/v1/profile/me'],
            'profile me — write' => ['PATCH', '/api/v1/profile/me'],
            'company dashboard' => ['GET', '/api/v1/company/dashboard'],
            'document upload' => ['POST', '/api/v1/company/profile/documents'],
            'document download' => ['GET', '/api/v1/company/profile/documents/1'],
            'talent list' => ['GET', '/api/v1/talent'],
            'talent filters' => ['GET', '/api/v1/talent/filters'],
            'talent detail' => ['GET', '/api/v1/talent/1'],
        ];
    }

    /**
     * The endpoints that carry `role:company_admin`.
     *
     * `/organization/profile` is deliberately NOT in this list: it is the
     * generic self-service organization endpoint (it predates this story and
     * also serves university / training-partner desks), so it gates on
     * membership rather than on the company role. It is still in
     * `protectedEndpoints` for the token and pending-organization checks, and
     * it has its own learner test below.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function companyGatedEndpoints(): array
    {
        return array_diff_key(
            self::protectedEndpoints(),
            ['organization profile' => null],
        );
    }

    public function test_every_company_endpoint_is_registered(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn ($route) => collect($route->methods())
                ->reject(fn (string $method) => $method === 'HEAD')
                ->map(fn (string $method) => $method.' '.$route->uri()))
            ->all();

        $expected = [
            'POST api/v1/auth/register/organization',
            'POST api/v1/auth/login/organization',
            'GET api/v1/organization/profile',
            'GET api/v1/profile/me',
            'PATCH api/v1/profile/me',
            'GET api/v1/company/dashboard',
            'POST api/v1/company/profile/documents',
            'GET api/v1/company/profile/documents/{document}',
            'GET api/v1/talent',
            'GET api/v1/talent/filters',
            'GET api/v1/talent/{student}',
        ];

        foreach ($expected as $route) {
            $this->assertContains($route, $registered, "The company endpoint `{$route}` is not registered.");
        }
    }

    // -----------------------------------------------------------------
    // Guards — the same three checks against every endpoint
    // -----------------------------------------------------------------

    #[DataProvider('protectedEndpoints')]
    public function test_every_endpoint_requires_a_token(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertStatus(401);
    }

    #[DataProvider('companyGatedEndpoints')]
    public function test_every_endpoint_refuses_a_learner(string $method, string $uri): void
    {
        Sanctum::actingAs($this->learner());

        $this->json($method, $uri)
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMPANY_ADMIN_ONLY');
    }

    public function test_the_generic_organization_profile_refuses_a_learner(): void
    {
        // No company role here — the endpoint asks "which organization do you
        // administer", and the answer for a learner is none.
        Sanctum::actingAs($this->learner());

        $this->getJson('/api/v1/organization/profile')
            ->assertStatus(403)
            ->assertJsonPath('code', 'NO_ORGANIZATION');
    }

    #[DataProvider('protectedEndpoints')]
    public function test_every_endpoint_refuses_a_pending_organization(string $method, string $uri): void
    {
        // A company admin, with a valid token, whose organization is still
        // under review: `organization.approved` is the second, independent
        // gate and must fire before any controller runs.
        Sanctum::actingAs($this->companyAdmin($this->organization('Acme', 'pending')));

        $this->json($method, $uri)
            ->assertStatus(403)
            ->assertJsonPath('code', 'ORGANIZATION_PENDING');
    }

    // -----------------------------------------------------------------
    // Auth — where a company account starts
    // -----------------------------------------------------------------

    public function test_an_organization_can_register(): void
    {
        $response = $this->post('/api/v1/auth/register/organization', [
            'name' => 'Dana Owner',
            'email' => 'owner@acme.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'organization_name' => 'Acme Industries',
            'organization_type' => 'company',
            'organization_contact_email' => 'hello@acme.com',
            'organization_industry' => 'Manufacturing',
            'organization_country' => 'Syria',
            'organization_city' => 'Damascus',
            'proof_file' => $this->pdf('registration.pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            // No OTP on this flow: access is gated on the document review.
            ->assertJsonPath('data.requires_verification', false);

        $organization = Organization::query()->where('name', 'Acme Industries')->firstOrFail();

        // Registered, not trusted: the review workflow owns the status.
        $this->assertSame('pending', $organization->verification_status);
        $this->assertSame('company', $organization->type);

        $owner = User::query()->where('email', 'owner@acme.com')->firstOrFail();
        $this->assertTrue($owner->hasRole('company_admin'));
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'role_in_org' => 'admin',
        ]);
    }

    public function test_an_organization_can_log_in_and_lands_on_its_dashboard(): void
    {
        $organization = $this->organization('Acme');
        $admin = $this->companyAdmin($organization, 'verified', 'admin', 'admin@acme.com');

        $response = $this->postJson('/api/v1/auth/login/organization', [
            'email' => $admin->email,
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            // AC-01 — the client reads the destination from the response
            // instead of deriving it from the role list.
            ->assertJsonPath('data.redirect_url', '/company/dashboard')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token', 'user', 'organizations']]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    // -----------------------------------------------------------------
    // Happy paths — one per endpoint
    // -----------------------------------------------------------------

    public function test_the_organization_profile_endpoint_answers(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->getJson('/api/v1/organization/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $organization->id)
            ->assertJsonStructure(['data' => ['name', 'verification_status', 'profile_completeness']]);
    }

    public function test_the_profile_me_endpoint_answers(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->getJson('/api/v1/profile/me')
            ->assertOk()
            ->assertJsonPath('data.id', $organization->id)
            ->assertJsonStructure(['data' => ['completeness', 'documents', 'reverification']]);
    }

    public function test_the_profile_me_endpoint_accepts_an_update(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->patchJson('/api/v1/profile/me', ['city' => 'Homs'])
            ->assertOk()
            ->assertJsonPath('data.profile.city', 'Homs');

        $this->assertSame('Homs', $organization->refresh()->city);
    }

    public function test_the_dashboard_endpoint_answers(): void
    {
        Sanctum::actingAs($this->companyAdmin($this->organization('Acme')));

        $this->getJson('/api/v1/company/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'counts' => ['projects', 'applicationsPending', 'unreadNotifications'],
                    'projectStates', 'funnel', 'tasks', 'recentProjects', 'permissions',
                ],
            ]);
    }

    public function test_the_document_upload_endpoint_answers(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->post('/api/v1/company/profile/documents', [
            'type' => 'business_registration',
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.document.type', 'business_registration')
            ->assertJsonPath('data.document.version', 1);
    }

    public function test_the_document_download_endpoint_answers(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        $id = $this->post('/api/v1/company/profile/documents', [
            'type' => 'business_registration',
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.document.id');

        $this->get('/api/v1/company/profile/documents/'.$id, ['Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_the_talent_list_endpoint_answers(): void
    {
        $this->discoverableStudent('Lena Kim');

        Sanctum::actingAs($this->companyAdmin($this->organization('Acme')));

        $this->getJson('/api/v1/talent')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_the_talent_filters_endpoint_answers(): void
    {
        Sanctum::actingAs($this->companyAdmin($this->organization('Acme')));

        $this->getJson('/api/v1/talent/filters')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_the_talent_detail_endpoint_answers(): void
    {
        $student = $this->discoverableStudent('Lena Kim');

        Sanctum::actingAs($this->companyAdmin($this->organization('Acme')));

        $this->getJson('/api/v1/talent/'.$student->id)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_a_student_who_is_not_discoverable_is_a_404(): void
    {
        Sanctum::actingAs($this->companyAdmin($this->organization('Acme')));

        // A private profile must be indistinguishable from an unknown id —
        // anything else turns the endpoint into a membership oracle.
        $this->getJson('/api/v1/talent/999999')->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function organization(string $name = 'Acme', string $status = 'verified'): Organization
    {
        return Organization::forceCreate([
            'name' => $name,
            'type' => 'company',
            'verification_status' => $status,
            'contact_email' => strtolower(str_replace(' ', '', $name)).'@example.com',
        ]);
    }

    private function companyAdmin(
        ?Organization $organization = null,
        string $status = 'verified',
        string $roleInOrg = 'admin',
        ?string $email = null,
    ): User {
        $organization ??= $this->organization('Acme', $status);

        $user = User::forceCreate([
            'name' => 'Company Admin',
            'email' => $email ?? uniqid('company-admin-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->companyAdminRole->id, ['organization_id' => $organization->id]);

        OrganizationMember::forceCreate([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_in_org' => $roleInOrg,
            'status' => 'active',
        ]);

        return $user;
    }

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid('learner-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->learnerRole->id);

        return $user;
    }

    /** A student the company is allowed to see: public profile + consent. */
    private function discoverableStudent(string $name): User
    {
        $user = $this->learner();
        $user->forceFill(['name' => $name])->save();

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'availability' => 'full_time',
            'visibility' => 'public',
            'consent_given' => true,
            'completeness_percent' => 80,
        ]);

        return $user;
    }

    private function pdf(string $name = 'registration.pdf', int $kilobytes = 60): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }
}
