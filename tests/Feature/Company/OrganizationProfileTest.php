<?php

namespace Tests\Feature\Company;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\UploadedFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * US-COMP-04 — Organization Profile Management.
 *
 * The story's fifteen testing bullets, in the order it lists them:
 *
 *   1  view the profile                      → test_company_admin_can_view_...
 *   2  update the profile                    → test_profile_can_be_updated_...
 *   3  a missing required field              → test_missing_required_field_...
 *   4  a bad e-mail / website / phone        → test_invalid_contact_details_...
 *   5  nothing is written when it fails      → test_profile_is_untouched_...
 *   6  upload a document                     → test_document_can_be_uploaded
 *   7  replace a document                    → test_replacing_a_document_...
 *   8  an unsupported file type              → test_unsupported_document_...
 *   9  an oversized file                     → test_oversized_document_...
 *   10 document access control               → test_a_document_of_another_...
 *   11 completeness                           → test_completeness_counts_...
 *   12 re-verification policy                 → test_reverification_* (three modes)
 *   13 the audit record                       → test_profile_update_and_document_..._are_audited
 *   14 cross-organization access              → test_an_admin_only_ever_edits_...
 *   15 learner direct access                  → test_a_learner_cannot_reach_...
 *
 * Plus the two things a reader of the code would otherwise have to assume:
 * the read-only fields are refused rather than ignored, and the legacy
 * `/organization/profile` path returns the very same payload.
 */
class OrganizationProfileTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    private Role $companyAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Uploads go to a fake local disk, so `available` is true and nothing
        // is written to the real storage directory during the suite.
        Storage::fake('local');

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->companyAdminRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** The enum is pending|verified|rejected — there is no 'approved' value. */
    private function organization(string $name = 'Acme', string $status = 'verified', array $attributes = []): Organization
    {
        return Organization::forceCreate(array_merge([
            'name' => $name,
            'type' => 'company',
            'verification_status' => $status,
            'contact_email' => strtolower(str_replace(' ', '', $name)).'@example.com',
        ], $attributes));
    }

    private function companyAdmin(?Organization $organization = null, string $status = 'verified', string $roleInOrg = 'admin'): User
    {
        $organization ??= $this->organization('Acme', $status);

        $user = User::forceCreate([
            'name' => 'Company Admin',
            'email' => uniqid('company-admin-').'@example.com',
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

    private function pdf(string $name = 'registration.pdf', int $kilobytes = 120): HttpUploadedFile
    {
        return HttpUploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function upload(Organization $organization, array $overrides = []): TestResponse
    {
        return $this->post('/api/v1/company/profile/documents', array_merge([
            'type' => 'business_registration',
            'file' => $this->pdf(),
        ], $overrides), ['Accept' => 'application/json']);
    }

    // -----------------------------------------------------------------
    // 1 — view
    // -----------------------------------------------------------------

    public function test_company_admin_can_view_the_profile(): void
    {
        $organization = $this->organization('SkillUp Ltd', 'verified', [
            'industry' => 'EdTech',
            'city' => 'Nablus',
            'country' => 'Palestine',
        ]);

        Sanctum::actingAs($this->companyAdmin($organization));

        $response = $this->getJson('/api/v1/profile/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $organization->id)
            ->assertJsonPath('data.name', 'SkillUp Ltd')
            ->assertJsonPath('data.type', 'company')
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'type', 'industry', 'company_size', 'website', 'description',
                    'country', 'city', 'address', 'postal_code', 'contact_email', 'contact_phone',
                    'logo', 'verification_status', 'verified_at',
                    'reverification' => ['required', 'requested_at', 'policy', 'fields', 'message'],
                    'documents' => ['business_registration', 'additional'],
                    'profile_completeness', 'profile_fields',
                    'completeness' => ['percent', 'version', 'missing'],
                    'profile_updated_at', 'profile_updated_by', 'updated_at',
                ],
            ]);
    }

    public function test_the_legacy_organization_profile_path_returns_the_same_payload(): void
    {
        $organization = $this->organization('SkillUp Ltd');

        Sanctum::actingAs($this->companyAdmin($organization));

        $legacy = $this->getJson('/api/v1/organization/profile');
        $srs = $this->getJson('/api/v1/profile/me');

        $legacy->assertOk()->assertJsonPath('data.id', $organization->id);

        // One service, two paths: a screen built on the old endpoint and one
        // built on the new one cannot disagree about the percentage.
        $this->assertSame($srs->json('data'), $legacy->json('data'));
    }

    // -----------------------------------------------------------------
    // 2 — update
    // -----------------------------------------------------------------

    public function test_profile_can_be_updated_and_the_change_is_persisted(): void
    {
        $organization = $this->organization('Acme');
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/v1/profile/me', [
            'name' => 'Acme Holdings',
            'city' => 'Aleppo',
            'website' => 'acme.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.profile.name', 'Acme Holdings')
            ->assertJsonPath('data.profile.city', 'Aleppo')
            // A scheme is added on the way in so every stored website can be
            // rendered as a link without the client guessing.
            ->assertJsonPath('data.profile.website', 'https://acme.com')
            ->assertJsonPath('data.changed', ['name', 'website', 'city']);

        $organization->refresh();

        $this->assertSame('Acme Holdings', $organization->name);
        $this->assertSame('https://acme.com', $organization->website);
        $this->assertNotNull($organization->profile_updated_at);
        $this->assertSame($admin->id, (int) $organization->profile_updated_by);
        $this->assertSame('v1', $organization->completeness_version);
        $this->assertNotNull($organization->profile_completeness);
    }

    public function test_a_patch_that_changes_nothing_is_a_no_op(): void
    {
        $organization = $this->organization('Acme', 'verified', ['city' => 'Nablus']);

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->patchJson('/api/v1/profile/me', ['name' => 'Acme', 'city' => 'Nablus'])
            ->assertOk()
            ->assertJsonPath('data.changed', []);

        // No write, therefore no "profile updated" audit row: an unchanged
        // save is not an edit.
        $this->assertDatabaseMissing('audit_events', ['action' => 'organization.profile.updated']);
    }

    // -----------------------------------------------------------------
    // 3 / 4 / 5 — validation, and what a failed save must NOT do
    // -----------------------------------------------------------------

    public function test_missing_required_field_is_rejected(): void
    {
        Sanctum::actingAs($this->companyAdmin($this->organization()));

        $response = $this->patchJson('/api/v1/profile/me', ['name' => '']);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'PROFILE_VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['name']]);
    }

    public function test_invalid_contact_details_are_rejected(): void
    {
        Sanctum::actingAs($this->companyAdmin($this->organization()));

        $this->patchJson('/api/v1/profile/me', ['contact_email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFILE_VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['contact_email']]);

        // A bare host is not a website: the `url` rule used to accept it once
        // a scheme was prepended, which is why there is a dedicated rule.
        $this->patchJson('/api/v1/profile/me', ['website' => 'acme'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['website']]);

        $this->patchJson('/api/v1/profile/me', ['contact_phone' => 'call me'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['contact_phone']]);
    }

    public function test_profile_is_untouched_after_a_failed_validation(): void
    {
        $organization = $this->organization('Acme', 'verified', ['city' => 'Nablus']);

        Sanctum::actingAs($this->companyAdmin($organization));

        // A valid field alongside an invalid one: the valid one must NOT be
        // written, or a user who mistypes their e-mail loses the rest of the
        // form.
        $this->patchJson('/api/v1/profile/me', [
            'name' => 'Renamed Ltd',
            'city' => 'Damascus',
            'contact_email' => 'not-an-email',
        ])->assertStatus(422);

        $organization->refresh();

        $this->assertSame('Acme', $organization->name);
        $this->assertSame('Nablus', $organization->city);
        $this->assertNull($organization->profile_updated_at);
    }

    public function test_the_contact_group_cannot_be_emptied(): void
    {
        $organization = $this->organization('Acme', 'verified', [
            'contact_email' => 'hello@acme.com',
            'contact_phone' => null,
        ]);

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->patchJson('/api/v1/profile/me', ['contact_email' => null])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFILE_CONTACT_REQUIRED')
            ->assertJsonPath('errors.fields', ['contact_email', 'contact_phone']);

        // Clearing the e-mail while a phone remains is fine — the rule is
        // "reachable", not "e-mail required".
        $this->patchJson('/api/v1/profile/me', ['contact_email' => null, 'contact_phone' => '+963 999 123 456'])
            ->assertOk()
            ->assertJsonPath('data.profile.contact_phone', '+963 999 123 456');
    }

    public function test_read_only_fields_are_refused_rather_than_ignored(): void
    {
        $organization = $this->organization('Acme', 'verified');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->patchJson('/api/v1/profile/me', ['verification_status' => 'pending'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'FIELD_NOT_EDITABLE')
            ->assertJsonPath('errors.fields', ['verification_status']);

        // BR-02 — `type` moves the organization into another role's scope.
        $this->patchJson('/api/v1/profile/me', ['type' => 'university'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'FIELD_NOT_EDITABLE');

        // Echoing the current values back — what a form seeded from the GET
        // does on every save — must still succeed.
        $this->patchJson('/api/v1/profile/me', [
            'name' => 'Acme',
            'type' => 'company',
            'verification_status' => 'verified',
        ])->assertOk();

        $organization->refresh();
        $this->assertSame('verified', $organization->verification_status);
        $this->assertSame('company', $organization->type);
    }

    // -----------------------------------------------------------------
    // 6 / 7 — documents
    // -----------------------------------------------------------------

    public function test_document_can_be_uploaded(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        $response = $this->upload($organization);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.document.type', 'business_registration')
            ->assertJsonPath('data.document.version', 1)
            ->assertJsonPath('data.document.status', 'pending')
            ->assertJsonPath('data.document.name', 'registration.pdf')
            ->assertJsonPath('data.replaced', null)
            ->assertJsonPath('data.profile.documents.business_registration.version', 1);

        $row = UploadedFile::query()->latest('id')->firstOrFail();

        $this->assertSame('certificate', $row->type);
        $this->assertSame(Organization::class, $row->fileable_type);
        $this->assertSame($organization->id, (int) $row->fileable_id);
        $this->assertNotNull($row->screened_at);
        Storage::disk('local')->assertExists($row->path);
    }

    public function test_replacing_a_document_keeps_the_previous_version(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        $first = $this->upload($organization)->assertOk()->json('data.document.id');

        $response = $this->upload($organization, ['file' => $this->pdf('registration-v2.pdf')]);

        $response->assertOk()
            ->assertJsonPath('data.document.version', 2)
            ->assertJsonPath('data.replaced.id', $first)
            ->assertJsonPath('data.profile.documents.business_registration.version', 2);

        // Nothing is deleted and nothing is updated in place: the superseded
        // row stays, so the audit trail can point at it.
        $this->assertSame(2, UploadedFile::query()->where('type', 'certificate')->count());

        $second = UploadedFile::query()->latest('id')->firstOrFail();
        $this->assertSame($first, (int) $second->replaces_id);
    }

    public function test_unsupported_document_type_is_rejected(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->upload($organization, [
            'file' => HttpUploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_TYPE_NOT_SUPPORTED');

        $this->assertSame(0, UploadedFile::query()->count());
    }

    public function test_oversized_document_is_rejected(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        // The limit is 5 MB (config/company.php, env-overridable).
        $this->upload($organization, ['file' => $this->pdf('huge.pdf', 6000)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_TOO_LARGE');

        $this->assertSame(0, UploadedFile::query()->count());
    }

    public function test_a_file_whose_content_does_not_match_its_extension_is_rejected(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        // A PHP webshell named .pdf — the case that turns an upload form into
        // a remote-code path if the storage directory is ever served.
        $this->upload($organization, [
            'file' => HttpUploadedFile::fake()->createWithContent(
                'invoice.pdf',
                "<?php system(\$_GET['c']); ?>\n",
            ),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_REJECTED_BY_SCAN');

        $this->assertSame(0, UploadedFile::query()->count());
    }

    public function test_the_antivirus_test_signature_is_rejected(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        // Assembled from halves here for the same reason the service does it:
        // the whole string is a signature, and a scanner that reads this test
        // file would quarantine it.
        $eicar = implode('', [
            'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-',
            'STANDARD-ANTIVIRUS-TEST-FILE!$H+H*',
        ]);

        $this->upload($organization, [
            'file' => HttpUploadedFile::fake()->createWithContent('sample.pdf', $eicar),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_REJECTED_BY_SCAN');

        $this->assertSame(0, UploadedFile::query()->count());
    }

    public function test_a_document_can_be_downloaded_by_its_own_organization(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        $id = $this->upload($organization)->assertOk()->json('data.document.id');

        $response = $this->get('/api/v1/company/profile/documents/'.$id, ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }

    // -----------------------------------------------------------------
    // 10 / 14 / 15 — who may touch what
    // -----------------------------------------------------------------

    public function test_a_document_of_another_organization_is_not_reachable(): void
    {
        $mine = $this->organization('Acme');
        $theirs = $this->organization('Globex');

        Sanctum::actingAs($this->companyAdmin($theirs));
        $theirDocument = $this->upload($theirs)->assertOk()->json('data.document.id');

        Sanctum::actingAs($this->companyAdmin($mine));

        // 404, not 403: for this caller the document does not exist, and a
        // 403 would confirm that an id belongs to somebody.
        $this->get('/api/v1/company/profile/documents/'.$theirDocument, ['Accept' => 'application/json'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'DOCUMENT_NOT_FOUND');
    }

    public function test_an_admin_only_ever_edits_the_organization_they_administrate(): void
    {
        $administered = $this->organization('Acme');
        $merelyJoined = $this->organization('Globex');

        $user = $this->companyAdmin($administered);

        // The same user is a plain member of a second organization.
        OrganizationMember::forceCreate([
            'organization_id' => $merelyJoined->id,
            'user_id' => $user->id,
            'role_in_org' => 'member',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile/me', ['name' => 'Renamed'])->assertOk();

        $this->assertSame('Renamed', $administered->refresh()->name);
        $this->assertSame('Globex', $merelyJoined->refresh()->name);
    }

    public function test_a_plain_member_is_refused_with_a_distinguishable_code(): void
    {
        $organization = $this->organization('Acme');

        // Has the company role, belongs to the organization — but not as its
        // admin, so there is nothing here for them to read.
        $member = $this->companyAdmin($organization, 'verified', 'member');

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/profile/me')
            ->assertStatus(403)
            ->assertJsonPath('code', 'NOT_ORGANIZATION_ADMIN');
    }

    public function test_a_learner_cannot_reach_the_company_profile(): void
    {
        Sanctum::actingAs($this->learner());

        $this->getJson('/api/v1/profile/me')
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMPANY_ADMIN_ONLY');

        $this->patchJson('/api/v1/profile/me', ['name' => 'Hijacked'])
            ->assertStatus(403);

        $this->post('/api/v1/company/profile/documents', [
            'type' => 'business_registration',
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_an_unapproved_organization_is_refused(): void
    {
        Sanctum::actingAs($this->companyAdmin($this->organization('Acme', 'pending')));

        $this->getJson('/api/v1/profile/me')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ORGANIZATION_PENDING');
    }

    // -----------------------------------------------------------------
    // 11 — completeness
    // -----------------------------------------------------------------

    public function test_completeness_counts_the_configured_groups(): void
    {
        // Filled: info (name + type), contact (e-mail), address (country + city).
        // Empty: size, website, description. → 3 of 6 groups = 50 %.
        $organization = $this->organization('Acme', 'verified', [
            'contact_email' => 'hello@acme.com',
            'country' => 'Syria',
            'city' => 'Damascus',
        ]);

        Sanctum::actingAs($this->companyAdmin($organization));

        $response = $this->getJson('/api/v1/profile/me');

        $response->assertOk()
            ->assertJsonPath('data.completeness.percent', 50)
            ->assertJsonPath('data.completeness.version', 'v1')
            ->assertJsonPath('data.profile_completeness', 50)
            ->assertJsonPath('data.completeness.missing', [
                ['field' => 'size', 'level' => 'recommended'],
                ['field' => 'website', 'level' => 'recommended'],
                ['field' => 'description', 'level' => 'recommended'],
            ]);

        // AC-09 — naming the missing fields is what lets the UI link to them.
        $fields = collect($response->json('data.profile_fields'))->keyBy('key');
        $this->assertTrue($fields['contact']['done']);
        $this->assertFalse($fields['website']['done']);
    }

    public function test_completeness_is_stored_as_a_snapshot_with_its_version(): void
    {
        $organization = $this->organization('Acme', 'verified', [
            'contact_email' => 'hello@acme.com',
            'country' => 'Syria',
            'city' => 'Damascus',
            'company_size' => '51-200',
        ]);

        Sanctum::actingAs($this->companyAdmin($organization));

        // Before: 4 of 6 groups (info, contact, address, size). Adding a
        // description makes it 5 of 6 — 83 %, and the snapshot records both
        // the number and the rule set that produced it.
        $this->patchJson('/api/v1/profile/me', ['description' => 'We build learning tools.'])
            ->assertOk()
            ->assertJsonPath('data.profile.completeness.percent', 83);

        $organization->refresh();
        $this->assertSame(83, (int) $organization->profile_completeness);
        $this->assertSame('v1', $organization->completeness_version);
    }

    // -----------------------------------------------------------------
    // 12 — re-verification policy (BR-09)
    // -----------------------------------------------------------------

    public function test_reverification_flags_the_organization_without_taking_its_access(): void
    {
        config(['company.profile.reverification.mode' => 'flag']);

        $organization = $this->organization('Acme', 'verified');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->patchJson('/api/v1/profile/me', ['name' => 'Acme Holdings'])
            ->assertOk()
            ->assertJsonPath('data.reverification_triggered', true)
            ->assertJsonPath('data.reverification.required', true)
            ->assertJsonPath('data.reverification.policy', 'flag');

        $organization->refresh();

        $this->assertNotNull($organization->reverification_required_at);
        // The default policy never revokes access for a rename.
        $this->assertSame('verified', $organization->verification_status);
    }

    public function test_a_field_that_is_not_verification_relevant_does_not_trigger_reverification(): void
    {
        $organization = $this->organization('Acme', 'verified');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->patchJson('/api/v1/profile/me', ['description' => 'A new description.'])
            ->assertOk()
            ->assertJsonPath('data.reverification_triggered', false);

        $this->assertNull($organization->refresh()->reverification_required_at);
    }

    public function test_replacing_the_registration_document_triggers_reverification(): void
    {
        $organization = $this->organization('Acme', 'verified');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->upload($organization)
            ->assertOk()
            ->assertJsonPath('data.reverification_triggered', true);

        $this->assertNotNull($organization->refresh()->reverification_required_at);
    }

    public function test_the_strict_policy_returns_the_organization_to_review(): void
    {
        config(['company.profile.reverification.mode' => 'pending']);

        $organization = $this->organization('Acme', 'verified');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->patchJson('/api/v1/profile/me', ['name' => 'Acme Holdings'])
            ->assertOk()
            ->assertJsonPath('data.reverification.policy', 'pending');

        $organization->refresh();

        $this->assertSame('pending', $organization->verification_status);
        $this->assertNull($organization->verified_at);
    }

    public function test_the_policy_can_be_turned_off_entirely(): void
    {
        config(['company.profile.reverification.mode' => 'off']);

        $organization = $this->organization('Acme', 'verified');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->patchJson('/api/v1/profile/me', ['name' => 'Acme Holdings'])
            ->assertOk()
            ->assertJsonPath('data.reverification_triggered', false)
            ->assertJsonPath('data.reverification.required', false);

        $this->assertNull($organization->refresh()->reverification_required_at);
        $this->assertSame('verified', $organization->verification_status);
    }

    // -----------------------------------------------------------------
    // 13 — the audit record
    // -----------------------------------------------------------------

    public function test_profile_update_and_document_upload_are_audited(): void
    {
        $organization = $this->organization('Acme');
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/profile/me', ['name' => 'Acme Holdings', 'city' => 'Aleppo'])
            ->assertOk();

        $event = AuditEvent::query()->where('action', 'organization.profile.updated')->latest('id')->firstOrFail();

        $this->assertSame($admin->id, (int) $event->actor_id);
        $this->assertSame('organization', $event->entity_type);
        $this->assertSame($organization->id, (int) $event->entity_id);
        // The audit row has to say what it was before, or it cannot answer
        // "who changed the company name, and from what".
        $this->assertSame('Acme', $event->before['name']);
        $this->assertSame('Acme Holdings', $event->after['name']);
        $this->assertArrayHasKey('reverification_required', $event->after);

        $this->upload($organization)->assertOk();

        $documentEvent = AuditEvent::query()->where('action', 'organization.document.uploaded')->latest('id')->firstOrFail();

        $this->assertSame('business_registration', $documentEvent->after['type']);
        $this->assertSame(1, $documentEvent->after['version']);
    }

    public function test_a_denied_profile_request_is_audited(): void
    {
        $organization = $this->organization('Acme');
        $member = $this->companyAdmin($organization, 'verified', 'member');

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/profile/me')->assertStatus(403);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'company.profile.denied',
            'actor_id' => $member->id,
        ]);
    }
}
