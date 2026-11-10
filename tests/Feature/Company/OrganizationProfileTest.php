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

class OrganizationProfileTest extends TestCase
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

    private function polyglot(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n"
            ."<?php system(\$_GET['c']); ?>\ntrailer<<>>\n%%EOF\n";
    }

    private function upload(Organization $organization, array $overrides = []): TestResponse
    {
        return $this->post('/api/v1/company/profile/documents', array_merge([
            'type' => 'business_registration',
            'file' => $this->pdf(),
        ], $overrides), ['Accept' => 'application/json']);
    }

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

        $this->assertSame($srs->json('data'), $legacy->json('data'));
    }

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

        $this->assertDatabaseMissing('audit_events', ['action' => 'organization.profile.updated']);
    }

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

        $this->patchJson('/api/v1/profile/me', ['type' => 'university'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'FIELD_NOT_EDITABLE');

        $this->patchJson('/api/v1/profile/me', [
            'name' => 'Acme',
            'type' => 'company',
            'verification_status' => 'verified',
        ])->assertOk();

        $organization->refresh();
        $this->assertSame('verified', $organization->verification_status);
        $this->assertSame('company', $organization->type);
    }

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

        $this->upload($organization, ['file' => $this->pdf('huge.pdf', 6000)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_TOO_LARGE');

        $this->assertSame(0, UploadedFile::query()->count());
    }

    public function test_a_script_wearing_a_document_extension_is_rejected(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        $response = $this->upload($organization, [
            'file' => HttpUploadedFile::fake()->createWithContent(
                'invoice.pdf',
                "<?php system(\$_GET['c']); ?>\n",
            ),
        ]);

        $response->assertStatus(422);

        $this->assertContains(
            $response->json('code'),
            ['DOCUMENT_TYPE_NOT_SUPPORTED', 'DOCUMENT_REJECTED_BY_SCAN'],
        );

        $this->assertSame(0, UploadedFile::query()->count());
    }

    public function test_a_pdf_polyglot_with_embedded_php_is_rejected_by_the_scan(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->upload($organization, [
            'file' => HttpUploadedFile::fake()->createWithContent('invoice.pdf', $this->polyglot()),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_REJECTED_BY_SCAN');

        $this->assertSame(0, UploadedFile::query()->count());
    }

    public function test_the_antivirus_test_signature_is_rejected(): void
    {
        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

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

    public function test_an_unreachable_engine_falls_back_to_the_signature_check(): void
    {
        config(['company.profile.documents.clamav.socket' => 'unix:///nonexistent/clamd.sock']);

        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->upload($organization)->assertOk();

        $this->upload($organization, [
            'file' => HttpUploadedFile::fake()->createWithContent('invoice.pdf', $this->polyglot()),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_REJECTED_BY_SCAN');
    }

    public function test_the_content_check_can_be_switched_off(): void
    {
        config(['company.profile.documents.scan' => false]);

        $organization = $this->organization('Acme');

        Sanctum::actingAs($this->companyAdmin($organization));

        $this->upload($organization, [
            'file' => HttpUploadedFile::fake()->createWithContent('invoice.pdf', $this->polyglot()),
        ])->assertOk();
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

    public function test_a_document_of_another_organization_is_not_reachable(): void
    {
        $mine = $this->organization('Acme');
        $theirs = $this->organization('Globex');

        Sanctum::actingAs($this->companyAdmin($theirs));
        $theirDocument = $this->upload($theirs)->assertOk()->json('data.document.id');

        Sanctum::actingAs($this->companyAdmin($mine));

        $this->get('/api/v1/company/profile/documents/'.$theirDocument, ['Accept' => 'application/json'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'DOCUMENT_NOT_FOUND');
    }

    public function test_an_admin_only_ever_edits_the_organization_they_administrate(): void
    {
        $administered = $this->organization('Acme');
        $merelyJoined = $this->organization('Globex');

        $user = $this->companyAdmin($administered);

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

    public function test_completeness_counts_the_configured_groups(): void
    {
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

        $this->patchJson('/api/v1/profile/me', ['description' => 'We build learning tools.'])
            ->assertOk()
            ->assertJsonPath('data.profile.completeness.percent', 83);

        $organization->refresh();
        $this->assertSame(83, (int) $organization->profile_completeness);
        $this->assertSame('v1', $organization->completeness_version);
    }

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
