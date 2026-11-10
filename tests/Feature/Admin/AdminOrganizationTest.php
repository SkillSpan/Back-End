<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Role;
use App\Models\UploadedFile;
use App\Models\User;
use App\Notifications\OrganizationApprovedNotification;
use App\Notifications\OrganizationRejectedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrganizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        Storage::fake('local');
        Notification::fake();
    }

    private function admin(): User
    {
        $user = User::forceCreate([
            'name' => 'Platform Admin',
            'email' => 'admin@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'admin')->first()->id);

        return $user;
    }

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'Plain Learner',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        return $user;
    }

    private function organization(string $status = 'pending'): Organization
    {
        return Organization::forceCreate([
            'name' => 'Test Company',
            'type' => 'company',
            'verification_status' => $status,
            'contact_email' => 'hr@company.com',
            'industry' => 'Software',
        ]);
    }

    private function attachOrgAdmin(Organization $organization, ?string $email = null): User
    {
        $member = User::forceCreate([
            'name' => 'Company Admin',
            'email' => $email ?? uniqid().'@company.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $organization->members()->attach($member->id, ['role_in_org' => 'admin']);

        return $member;
    }

    private function proofFile(Organization $organization, string $status = 'pending'): UploadedFile
    {
        Storage::disk('local')->put('proofs/proof.pdf', '%PDF-1.4 fake proof');

        return UploadedFile::forceCreate([
            'fileable_type' => Organization::class,
            'fileable_id' => $organization->id,
            'type' => 'certificate',
            'path' => 'proofs/proof.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1234,
            'status' => $status,
        ]);
    }

    public function test_endpoints_require_authentication(): void
    {
        $org = $this->organization();

        $this->getJson('/api/v1/admin/organizations')->assertStatus(401);
        $this->getJson("/api/v1/admin/organizations/{$org->id}")->assertStatus(401);
        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")->assertStatus(401);
        $this->postJson("/api/v1/admin/organizations/{$org->id}/reject")->assertStatus(401);
        $this->getJson("/api/v1/admin/organizations/{$org->id}/proof-file")->assertStatus(401);
    }

    public function test_non_admin_users_are_forbidden_everywhere(): void
    {
        Sanctum::actingAs($this->learner());
        $org = $this->organization();

        $this->getJson('/api/v1/admin/organizations')->assertStatus(403);
        $this->getJson("/api/v1/admin/organizations/{$org->id}")->assertStatus(403);
        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")->assertStatus(403);
        $this->postJson("/api/v1/admin/organizations/{$org->id}/reject")->assertStatus(403);
        $this->getJson("/api/v1/admin/organizations/{$org->id}/proof-file")->assertStatus(403);
    }

    public function test_index_lists_organizations_and_filters_by_status(): void
    {
        Sanctum::actingAs($this->admin());

        $pendingA = $this->organization('pending');
        $verifiedB = $this->organization('verified');

        $all = $this->getJson('/api/v1/admin/organizations')->assertOk();
        $this->assertSame(2, count($all->json('data.data')));

        $filtered = $this->getJson('/api/v1/admin/organizations?status=pending')->assertOk();
        $ids = collect($filtered->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($pendingA->id));
        $this->assertFalse($ids->contains($verifiedB->id));

        $this->getJson('/api/v1/admin/organizations?status=;DROP TABLE organizations')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_the_index_clamps_per_page_to_a_safe_maximum(): void
    {
        Sanctum::actingAs($this->admin());

        $this->organization();
        $this->organization();
        $this->organization();

        $this->getJson('/api/v1/admin/organizations?per_page=1000000')
            ->assertOk()
            ->assertJsonPath('data.per_page', 100);

        $this->getJson('/api/v1/admin/organizations')
            ->assertOk()
            ->assertJsonPath('data.per_page', 15);

        $this->getJson('/api/v1/admin/organizations?per_page=2')
            ->assertOk()
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonPath('data.total', 3);
    }

    public function test_a_non_positive_per_page_falls_back_to_the_floor(): void
    {
        Sanctum::actingAs($this->admin());

        $this->organization();

        foreach (['0', '-5'] as $value) {
            $this->getJson("/api/v1/admin/organizations?per_page={$value}")
                ->assertOk()
                ->assertJsonPath('data.per_page', 1);
        }
    }

    public function test_show_returns_the_organization_with_verifier_and_proof_file(): void
    {
        $adminUser = $this->admin();
        $org = $this->organization('verified');
        $org->forceFill(['verified_by' => $adminUser->id])->save();
        $this->proofFile($org, 'approved');

        Sanctum::actingAs($adminUser);

        $response = $this->getJson("/api/v1/admin/organizations/{$org->id}")->assertOk();

        $response
            ->assertJsonPath('data.name', 'Test Company')
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.verified_by.email', 'admin@test.com')
            ->assertJsonPath('data.proof_file.status', 'approved')
            ->assertJsonPath('data.proof_file.download_url', route('admin.organizations.proof-file', $org->id));
    }

    public function test_show_returns_the_organization_description(): void
    {
        Sanctum::actingAs($this->admin());

        $org = $this->organization();
        $org->forceFill(['description' => 'A software company building developer tools.'])->save();

        $this->getJson("/api/v1/admin/organizations/{$org->id}")
            ->assertOk()
            ->assertJsonPath('data.description', 'A software company building developer tools.');
    }

    public function test_a_description_submitted_at_registration_reaches_the_admin_panel(): void
    {
        $admin = $this->admin();

        $this->post('/api/v1/auth/register/organization', [
            'name' => 'Org Owner',
            'email' => 'owner@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => 1,
            'privacy_accepted' => 1,
            'organization_name' => 'Taqat LLC',
            'organization_type' => 'company',
            'organization_contact_email' => 'contact@taqat.example.com',
            'organization_description' => 'We build developer tools for Arabic-speaking teams.',
            'organization_industry' => 'Software & IT Services',
            'proof_file' => \Illuminate\Http\UploadedFile::fake()
                ->create('proof.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $org = Organization::where('name', 'Taqat LLC')->firstOrFail();
        $this->assertSame(
            'We build developer tools for Arabic-speaking teams.',
            $org->description
        );

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/admin/organizations/{$org->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.description',
                'We build developer tools for Arabic-speaking teams.'
            );
    }

    public function test_every_registration_detail_is_stored_and_returned_to_the_admin_panel(): void
    {
        $admin = $this->admin();

        $this->post('/api/v1/auth/register/organization', [
            'name' => 'Detail Owner',
            'email' => 'details@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => 1,
            'privacy_accepted' => 1,
            'organization_name' => 'Northwind Traders',
            'organization_type' => 'company',
            'organization_contact_email' => 'hello@northwind.example.com',
            'organization_contact_phone' => '+962791234567',
            'organization_website' => 'https://northwind.example.com',
            'organization_description' => 'We build developer tooling for the region.',
            'organization_industry' => 'Software & IT Services',
            'organization_company_size' => '11 - 50 employees',
            'organization_country' => 'Jordan',
            'organization_city' => 'Amman',
            'organization_address' => '123 Main St',
            'organization_postal_code' => '11183',
            'proof_file' => \Illuminate\Http\UploadedFile::fake()
                ->create('proof.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $org = Organization::where('name', 'Northwind Traders')->firstOrFail();

        $this->assertSame('hello@northwind.example.com', $org->contact_email);
        $this->assertSame('+962791234567', $org->contact_phone);
        $this->assertSame('https://northwind.example.com', $org->website);
        $this->assertSame('We build developer tooling for the region.', $org->description);
        $this->assertSame('Software & IT Services', $org->industry);
        $this->assertSame('11 - 50 employees', $org->company_size);
        $this->assertSame('Jordan', $org->country);
        $this->assertSame('Amman', $org->city);
        $this->assertSame('123 Main St', $org->address);
        $this->assertSame('11183', $org->postal_code);

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/admin/organizations/{$org->id}")
            ->assertOk()
            ->assertJsonPath('data.contact_email', 'hello@northwind.example.com')
            ->assertJsonPath('data.contact_phone', '+962791234567')
            ->assertJsonPath('data.website', 'https://northwind.example.com')
            ->assertJsonPath('data.description', 'We build developer tooling for the region.')
            ->assertJsonPath('data.industry', 'Software & IT Services')
            ->assertJsonPath('data.company_size', '11 - 50 employees')
            ->assertJsonPath('data.country', 'Jordan')
            ->assertJsonPath('data.city', 'Amman')
            ->assertJsonPath('data.address', '123 Main St')
            ->assertJsonPath('data.postal_code', '11183');
    }

    public function test_the_organization_list_carries_every_registration_detail(): void
    {
        Sanctum::actingAs($this->admin());

        Organization::forceCreate([
            'name' => 'Listed Co',
            'type' => 'company',
            'verification_status' => 'pending',
            'contact_email' => 'listed@example.com',
            'website' => 'https://listed.example.com',
            'description' => 'Listed description.',
            'industry' => 'Education',
            'company_size' => '51 - 200 employees',
            'country' => 'Jordan',
            'city' => 'Irbid',
            'address' => '9 University St',
            'postal_code' => '21110',
        ]);

        $listedId = Organization::where('name', 'Listed Co')->firstOrFail()->id;

        $response = $this->getJson('/api/v1/admin/organizations')->assertOk();

        $row = collect($response->json('data.data'))
            ->firstWhere('id', $listedId);

        $this->assertNotNull($row, 'The newly created organization was not in the list.');
        $this->assertSame('https://listed.example.com', $row['website']);
        $this->assertSame('Listed description.', $row['description']);
        $this->assertSame('Education', $row['industry']);
        $this->assertSame('51 - 200 employees', $row['company_size']);
        $this->assertSame('Jordan', $row['country']);
        $this->assertSame('Irbid', $row['city']);
        $this->assertSame('9 University St', $row['address']);
        $this->assertSame('21110', $row['postal_code']);
    }

    public function test_show_returns_a_null_description_when_none_was_submitted(): void
    {
        Sanctum::actingAs($this->admin());
        $org = $this->organization();

        $response = $this->getJson("/api/v1/admin/organizations/{$org->id}")->assertOk();

        $response->assertJsonPath('data.description', null);
        $this->assertArrayHasKey('description', $response->json('data'));
    }

    public function test_the_review_panel_renders_every_organization_detail(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/organizations')
            ->assertOk()
            ->assertSee('Postal code', false)
            ->assertSee('Address', false)
            ->assertSee('Industry', false)
            ->assertSee('Country', false)
            ->assertSee('City', false)
            ->assertSee('Company size', false)
            ->assertSee('Website', false)
            ->assertSee('Email', false)
            ->assertSee('Phone', false);
    }

    public function test_the_review_panel_keeps_the_empty_description_copy(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/organizations')
            ->assertOk()
            ->assertSee('No description was provided for this organization.', false);
    }

    public function test_show_reports_a_proof_file_as_available_when_it_is_on_disk(): void
    {
        Sanctum::actingAs($this->admin());
        $org = $this->organization();
        $this->proofFile($org);

        $this->getJson("/api/v1/admin/organizations/{$org->id}")
            ->assertOk()
            ->assertJsonPath('data.proof_file.available', true);
    }

    public function test_show_reports_a_proof_file_as_unavailable_when_the_file_is_gone(): void
    {
        Sanctum::actingAs($this->admin());
        $org = $this->organization();
        $this->proofFile($org);

        Storage::disk('local')->delete('proofs/proof.pdf');

        $response = $this->getJson("/api/v1/admin/organizations/{$org->id}")->assertOk();

        $response
            ->assertJsonPath('data.proof_file.available', false)
            ->assertJsonPath('data.proof_file.mime_type', 'application/pdf');
    }

    public function test_show_reports_a_null_proof_file_when_nothing_was_uploaded(): void
    {
        Sanctum::actingAs($this->admin());
        $org = $this->organization();

        $this->getJson("/api/v1/admin/organizations/{$org->id}")
            ->assertOk()
            ->assertJsonPath('data.proof_file', null);
    }

    public function test_approve_verifies_a_pending_organization_with_full_audit_trail(): void
    {
        $adminUser = $this->admin();
        $org = $this->organization();
        $orgAdmin = $this->attachOrgAdmin($org);
        $proof = $this->proofFile($org);

        Sanctum::actingAs($adminUser);

        $response = $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');

        $fresh = $org->fresh();
        $this->assertSame('verified', $fresh->verification_status);
        $this->assertSame($adminUser->id, $fresh->verified_by);
        $this->assertNotNull($fresh->verified_at);
        $this->assertSame($adminUser->id, $response->json('data.verified_by.id'));

        $this->assertSame('approved', $proof->fresh()->status);

        $audit = AuditEvent::where('action', 'organization.approved')
            ->where('entity_id', $org->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($adminUser->id, $audit->actor_id);
        $this->assertSame('pending', $audit->before['verification_status']);
        $this->assertSame('verified', $audit->after['verification_status']);

        Notification::assertSentTo($orgAdmin, OrganizationApprovedNotification::class);
    }

    public function test_approve_rejects_an_already_reviewed_organization(): void
    {
        Sanctum::actingAs($this->admin());
        $org = $this->organization('rejected');

        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This organization has already been reviewed.')
            ->assertJsonPath('data.verification_status', 'rejected');
    }

    public function test_reject_records_reason_and_notifies_org_admin(): void
    {
        $adminUser = $this->admin();
        $org = $this->organization();
        $orgAdmin = $this->attachOrgAdmin($org);
        $proof = $this->proofFile($org);

        Sanctum::actingAs($adminUser);

        $this->postJson("/api/v1/admin/organizations/{$org->id}/reject", [
            'reason' => 'The uploaded certificate is not readable.',
        ])->assertOk()
            ->assertJsonPath('data.verification_status', 'rejected');

        $fresh = $org->fresh();
        $this->assertSame('rejected', $fresh->verification_status);
        $this->assertSame($adminUser->id, $fresh->verified_by);
        $this->assertSame('rejected', $proof->fresh()->status);

        $audit = AuditEvent::where('action', 'organization.rejected')
            ->where('entity_id', $org->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame(
            'The uploaded certificate is not readable.',
            $audit->after['reason']
        );

        Notification::assertSentTo($orgAdmin, OrganizationRejectedNotification::class);
    }

    public function test_reject_works_without_a_reason_and_validates_reason_length(): void
    {
        Sanctum::actingAs($this->admin());
        $withNoReason = $this->organization();

        $this->postJson("/api/v1/admin/organizations/{$withNoReason->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'rejected');

        $untouched = $this->organization();
        $this->postJson("/api/v1/admin/organizations/{$untouched->id}/reject", [
            'reason' => str_repeat('x', 1001),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertSame('pending', $untouched->fresh()->verification_status);
    }

    public function test_proof_file_can_be_downloaded_by_admin(): void
    {
        Sanctum::actingAs($this->admin());
        $org = $this->organization();
        $this->proofFile($org);

        $response = $this->getJson("/api/v1/admin/organizations/{$org->id}/proof-file");

        $response->assertOk();
        $this->assertSame('%PDF-1.4 fake proof', $response->streamedContent());
    }

    public function test_proof_download_follows_the_configured_default_disk(): void
    {
        Sanctum::actingAs($this->admin());

        Storage::fake('persistent-cloud');
        config(['filesystems.default' => 'persistent-cloud']);

        $org = $this->organization();
        Storage::disk('persistent-cloud')->put('proofs/proof.pdf', '%PDF-1.4 on the cloud disk');

        UploadedFile::forceCreate([
            'fileable_type' => Organization::class,
            'fileable_id' => $org->id,
            'type' => 'certificate',
            'path' => 'proofs/proof.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1234,
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/v1/admin/organizations/{$org->id}/proof-file");

        $response->assertOk();
        $this->assertSame('%PDF-1.4 on the cloud disk', $response->streamedContent());
    }

    public function test_missing_proof_file_returns_404_not_an_error_page(): void
    {
        Sanctum::actingAs($this->admin());
        $org = $this->organization();

        $this->getJson("/api/v1/admin/organizations/{$org->id}/proof-file")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Proof file not found.');

        $ghost = $this->proofFile($this->organization());
        Storage::disk('local')->delete($ghost->path);

        $this->getJson("/api/v1/admin/organizations/{$ghost->fileable_id}/proof-file")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Proof file not found.');
    }

    public function test_repeated_approval_does_not_send_a_second_email(): void
    {
        Sanctum::actingAs($this->admin());

        $org = $this->organization();
        $orgAdmin = $this->attachOrgAdmin($org, 'owner@company.com');
        $this->proofFile($org);

        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');

        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This organization has already been reviewed.')
            ->assertJsonPath('data.verification_status', 'verified');

        Notification::assertSentToTimes($orgAdmin, OrganizationApprovedNotification::class, 1);

        $this->assertSame(
            1,
            AuditEvent::where('action', 'organization.approved')->where('entity_id', $org->id)->count()
        );
    }

    public function test_repeated_rejection_does_not_send_a_second_email(): void
    {
        Sanctum::actingAs($this->admin());

        $org = $this->organization();
        $orgAdmin = $this->attachOrgAdmin($org, 'owner@company.com');
        $this->proofFile($org);

        $this->postJson("/api/v1/admin/organizations/{$org->id}/reject", ['reason' => 'Unreadable.'])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'rejected');

        $this->postJson("/api/v1/admin/organizations/{$org->id}/reject", ['reason' => 'Unreadable.'])
            ->assertStatus(422)
            ->assertJsonPath('data.verification_status', 'rejected');

        Notification::assertSentToTimes($orgAdmin, OrganizationRejectedNotification::class, 1);
    }

    public function test_approval_email_is_addressed_to_the_organization_account_email(): void
    {
        Sanctum::actingAs($this->admin());

        $org = $this->organization();
        $orgAdmin = $this->attachOrgAdmin($org, 'owner@company.com');
        $this->proofFile($org);

        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")->assertOk();

        Notification::assertSentTo(
            $orgAdmin,
            OrganizationApprovedNotification::class,
            function ($notification, $channels) use ($orgAdmin) {
                $this->assertContains('mail', $channels);

                return $orgAdmin->routeNotificationFor('mail') === 'owner@company.com';
            }
        );

        $this->assertNotSame($org->contact_email, $orgAdmin->routeNotificationFor('mail'));
    }

    public function test_a_removed_org_admin_is_not_notified(): void
    {
        Sanctum::actingAs($this->admin());

        $org = $this->organization();
        $formerAdmin = $this->attachOrgAdmin($org, 'former@company.com');
        $org->members()->updateExistingPivot($formerAdmin->id, ['status' => 'removed']);
        $this->proofFile($org);

        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")->assertOk();

        Notification::assertNotSentTo($formerAdmin, OrganizationApprovedNotification::class);
    }

    public function test_approval_is_refused_without_a_proof_file_and_notifies_nobody(): void
    {
        Sanctum::actingAs($this->admin());

        $org = $this->organization();
        $orgAdmin = $this->attachOrgAdmin($org, 'owner@company.com');

        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This organization has no proof document to review. It cannot be approved.');

        $this->assertSame('pending', $org->fresh()->verification_status);
        Notification::assertNothingSentTo($orgAdmin);
    }

    public function test_organization_review_emails_render_with_real_data(): void
    {
        $org = $this->organization();
        $orgAdmin = $this->attachOrgAdmin($org, 'owner@company.com');

        $approved = (new OrganizationApprovedNotification($org))->toMail($orgAdmin)->render();
        $this->assertStringContainsString($org->name, $approved);
        $this->assertStringContainsString($orgAdmin->name, $approved);

        $rejected = (new OrganizationRejectedNotification($org, 'The certificate is unreadable.'))
            ->toMail($orgAdmin)
            ->render();
        $this->assertStringContainsString($org->name, $rejected);
        $this->assertStringContainsString('The certificate is unreadable.', $rejected);

        $withoutReason = (new OrganizationRejectedNotification($org))->toMail($orgAdmin)->render();
        $this->assertStringContainsString($org->name, $withoutReason);
    }

    public function test_approval_logs_the_recipients_it_notified(): void
    {
        Log::spy();

        Sanctum::actingAs($this->admin());
        $org = $this->organization();
        $this->attachOrgAdmin($org, 'owner@company.com');
        $this->proofFile($org);

        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")->assertOk();

        Log::shouldHaveReceived('info')->once();
    }

    public function test_approval_with_no_active_admin_logs_a_warning_and_sends_nothing(): void
    {
        Log::spy();

        Sanctum::actingAs($this->admin());
        $org = $this->organization();
        $removed = $this->attachOrgAdmin($org, 'owner@company.com');
        $org->members()->updateExistingPivot($removed->id, ['status' => 'removed']);
        $this->proofFile($org);

        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")->assertOk();

        Notification::assertNothingSentTo($removed);
        Log::shouldHaveReceived('warning')->once();
    }
}
