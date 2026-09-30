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

/**
 * Platform-admin review workflow for organization registrations
 * (SRS PROF-02 / ADM-01 / ADM-03): list, inspect, approve, reject,
 * download the proof document — plus the authorization and audit trail
 * guarantees around every action.
 */
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

    /**
     * Attach a member as the organization's own admin (pivot role_in_org)
     * so approval/rejection notifications have a recipient.
     *
     * The email is overridable so a test can pin the exact address the review
     * email is expected to reach.
     */
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
            'type' => 'certificate', // proofFile() resolves through type=certificate
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

        // Default listing includes everything.
        $all = $this->getJson('/api/v1/admin/organizations')->assertOk();
        $this->assertSame(2, count($all->json('data.data')));

        // Status filter narrows it down.
        $filtered = $this->getJson('/api/v1/admin/organizations?status=pending')->assertOk();
        $ids = collect($filtered->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($pendingA->id));
        $this->assertFalse($ids->contains($verifiedB->id));

        // Unknown status values are ignored rather than leaking SQL.
        $this->getJson('/api/v1/admin/organizations?status=;DROP TABLE organizations')
            ->assertOk()
            ->assertJsonPath('success', true);
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
            // The generated download link must point at the named route.
            ->assertJsonPath('data.proof_file.download_url', route('admin.organizations.proof-file', $org->id));
    }

    /**
     * Regression: the detail payload omitted `description`, so the review
     * panel rendered "no description submitted" for every organization —
     * including the ones that had submitted one.
     */
    public function test_show_returns_the_organization_description(): void
    {
        Sanctum::actingAs($this->admin());

        $org = $this->organization();
        $org->forceFill(['description' => 'A software company building developer tools.'])->save();

        $this->getJson("/api/v1/admin/organizations/{$org->id}")
            ->assertOk()
            ->assertJsonPath('data.description', 'A software company building developer tools.');
    }

    /**
     * The test above proves the read side against a hand-seeded row. It does
     * NOT prove that a description submitted through the real registration
     * endpoint ever reaches the database — and that gap is exactly where the
     * bug could come back: `AuthService::createOrganization` builds the row
     * with `Organization::forceCreate([...])` and maps the request's
     * `organization_description` onto the `description` column by hand. If
     * that mapping is dropped, or the key is renamed on one side only, the
     * read-side test still passes (it writes the column directly) while every
     * real organization silently loses its description.
     *
     * Registration uses `multipart/form-data` because of the required
     * `proof_file`, so this walks the whole chain: register -> stored ->
     * returned by the admin detail endpoint.
     */
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

        // 1) The value actually landed in the column...
        $org = Organization::where('name', 'Taqat LLC')->firstOrFail();
        $this->assertSame(
            'We build developer tools for Arabic-speaking teams.',
            $org->description
        );

        // 2) ...and comes back out through the panel's own endpoint.
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/admin/organizations/{$org->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.description',
                'We build developer tools for Arabic-speaking teams.'
            );
    }

    /**
     * Every organization detail the registration form collects must survive
     * the round trip. The frontend previously sent these seven values under
     * unprefixed names (`description`, `website`, `country`, ...) which match
     * no validation rule, so they arrived as null and were silently dropped -
     * the root cause of the admin panel reporting "No description was
     * provided" for an organization whose owner had typed one.
     */
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

        // The database rows, not just the response body.
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

        // ...and the same values reach the panel's endpoint.
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

    /**
     * The same round trip reaches the list endpoint the panel loads first, so
     * a card is never rendered from a half-populated record.
     */
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

        // The list is `latest()` first and paginated (the rows live under
        // data.data), so locate this record by id instead of assuming index 0.
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

    /**
     * An organization that genuinely submitted no description must still come
     * back with the key present and null, so the panel can tell the two cases
     * apart instead of relying on an absent key.
     */
    public function test_show_returns_a_null_description_when_none_was_submitted(): void
    {
        Sanctum::actingAs($this->admin());
        $org = $this->organization();

        $response = $this->getJson("/api/v1/admin/organizations/{$org->id}")->assertOk();

        $response->assertJsonPath('data.description', null);
        $this->assertArrayHasKey('description', $response->json('data'));
    }

    /**
     * The expanded review card must offer every detail the registration form
     * collects, not just the four it used to show.
     *
     * This is a regression guard on the panel's own template: it used to render
     * email / phone / website / company size and silently discard industry,
     * country, city, address and postal code — all of which the API had been
     * returning the whole time. A reviewer could not see an organization's
     * address before deciding to approve it.
     *
     * The template is inline JavaScript in the Blade view, so asserting on the
     * served page is the honest way to check it: a label that is missing from
     * the response cannot be rendered by the browser either.
     */
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

    /**
     * The empty-description copy must survive: it is the exact string the panel
     * shows when the column really is null, and the whole point of the bug
     * report was that it was appearing for organizations that HAD a
     * description. Keeping it in the template (rather than deleting it) means
     * the two cases stay distinguishable.
     */
    public function test_the_review_panel_keeps_the_empty_description_copy(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/organizations')
            ->assertOk()
            ->assertSee('No description was provided for this organization.', false);
    }

    /**
     * The happy path: the row and the file agree, so the panel shows a link.
     */
    public function test_show_reports_a_proof_file_as_available_when_it_is_on_disk(): void
    {
        Sanctum::actingAs($this->admin());
        $org = $this->organization();
        $this->proofFile($org);

        $this->getJson("/api/v1/admin/organizations/{$org->id}")
            ->assertOk()
            ->assertJsonPath('data.proof_file.available', true);
    }

    /**
     * The failure this flag exists for: the DB row survives but the bytes are
     * gone — which is what happens on every deploy of a container whose
     * filesystem has no persistent volume. Without this distinction the panel
     * rendered a link to a 404 and the admin could not tell a lost upload from
     * an upload that never happened.
     */
    public function test_show_reports_a_proof_file_as_unavailable_when_the_file_is_gone(): void
    {
        Sanctum::actingAs($this->admin());
        $org = $this->organization();
        $this->proofFile($org);

        // The upload is destroyed but the database row is untouched.
        Storage::disk('local')->delete('proofs/proof.pdf');

        $response = $this->getJson("/api/v1/admin/organizations/{$org->id}")->assertOk();

        $response
            ->assertJsonPath('data.proof_file.available', false)
            // The metadata still comes back, so the admin can see what was lost.
            ->assertJsonPath('data.proof_file.mime_type', 'application/pdf');
    }

    /**
     * An organization that never uploaded anything must report `proof_file`
     * as null rather than as an object with `available: false`, otherwise the
     * panel would accuse the server of losing a file that never existed.
     */
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

        // Organization state flipped, with reviewer + timestamp recorded.
        $fresh = $org->fresh();
        $this->assertSame('verified', $fresh->verification_status);
        $this->assertSame($adminUser->id, $fresh->verified_by);
        $this->assertNotNull($fresh->verified_at);
        $this->assertSame($adminUser->id, $response->json('data.verified_by.id'));

        // Proof document promoted to approved.
        $this->assertSame('approved', $proof->fresh()->status);

        // Security-event audit record with before/after snapshots (§12.1).
        $audit = AuditEvent::where('action', 'organization.approved')
            ->where('entity_id', $org->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($adminUser->id, $audit->actor_id);
        $this->assertSame('pending', $audit->before['verification_status']);
        $this->assertSame('verified', $audit->after['verification_status']);

        // The organization's own admins are notified.
        Notification::assertSentTo($orgAdmin, OrganizationApprovedNotification::class);
    }

    public function test_approve_rejects_an_already_reviewed_organization(): void
    {
        Sanctum::actingAs($this->admin());
        $org = $this->organization('rejected'); // already reviewed once

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

        // Reason lands inside the audit trail's "after" snapshot.
        $audit = AuditEvent::where('action', 'organization.rejected')
            ->where('entity_id', $org->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame(
            'The uploaded certificate is not readable.',
            $audit->after['reason']
        );

        // The rejection notification reached every organization admin.
        Notification::assertSentTo($orgAdmin, OrganizationRejectedNotification::class);
    }

    public function test_reject_works_without_a_reason_and_validates_reason_length(): void
    {
        Sanctum::actingAs($this->admin());
        $withNoReason = $this->organization();

        // Optional reason omitted entirely → still succeeds.
        $this->postJson("/api/v1/admin/organizations/{$withNoReason->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'rejected');

        // Reason beyond 1000 chars → validation error, org untouched.
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
        // Storage::response() returns a StreamedResponse — read it as such.
        $this->assertSame('%PDF-1.4 fake proof', $response->streamedContent());
    }

    /**
     * Regression: the proof download hardcoded the 'local' disk. That disk
     * lives inside the deployment container, so every deploy wipes it and the
     * panel then shows a proof whose file no longer exists. Following the
     * configured default disk is what allows FILESYSTEM_DISK to point uploads
     * at storage that persists — this proves the download reads from wherever
     * the default points, not from a fixed disk name.
     */
    public function test_proof_download_follows_the_configured_default_disk(): void
    {
        Sanctum::actingAs($this->admin());

        // A disk that is deliberately NOT 'local': if any code path still
        // hardcoded 'local', the file would not be found and this would 404.
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
        $org = $this->organization(); // no file row at all

        $this->getJson("/api/v1/admin/organizations/{$org->id}/proof-file")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Proof file not found.');

        // A file row whose backing storage object vanished behaves the same.
        $ghost = $this->proofFile($this->organization());
        Storage::disk('local')->delete($ghost->path);

        $this->getJson("/api/v1/admin/organizations/{$ghost->fileable_id}/proof-file")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Proof file not found.');
    }

    /**
     * A repeated approval must not produce a second email.
     *
     * The panel disables the button after the first click, but a double-click
     * or a client retry after a timeout still reaches the endpoint twice, and
     * the organization's admins must not be congratulated twice for one
     * decision.
     */
    public function test_repeated_approval_does_not_send_a_second_email(): void
    {
        Sanctum::actingAs($this->admin());

        $org = $this->organization();
        $orgAdmin = $this->attachOrgAdmin($org, 'owner@company.com');
        $this->proofFile($org);

        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');

        // The second call is answered exactly like any other repeat.
        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This organization has already been reviewed.')
            ->assertJsonPath('data.verification_status', 'verified');

        Notification::assertSentToTimes($orgAdmin, OrganizationApprovedNotification::class, 1);

        // And only one audit record was written for the one decision.
        $this->assertSame(
            1,
            AuditEvent::where('action', 'organization.approved')->where('entity_id', $org->id)->count()
        );
    }

    /**
     * Same guarantee on the rejection side, so fixing approval cannot have
     * introduced a difference between the two paths.
     */
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

    /**
     * The review email goes to the ACCOUNT that administers the organization
     * — the address the user logs in with — not to `contact_email`, which is a
     * public contact address the organization may not control.
     */
    public function test_approval_email_is_addressed_to_the_organization_account_email(): void
    {
        Sanctum::actingAs($this->admin());

        $org = $this->organization(); // contact_email: hr@company.com
        $orgAdmin = $this->attachOrgAdmin($org, 'owner@company.com');
        $this->proofFile($org);

        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")->assertOk();

        Notification::assertSentTo(
            $orgAdmin,
            OrganizationApprovedNotification::class,
            function ($notification, $channels) use ($orgAdmin) {
                $this->assertContains('mail', $channels);

                // Notifiable routes mail to the account's own address.
                return $orgAdmin->routeNotificationFor('mail') === 'owner@company.com';
            }
        );

        $this->assertNotSame($org->contact_email, $orgAdmin->routeNotificationFor('mail'));
    }

    /**
     * A membership marked 'removed' is not the organization any more, so it
     * must not receive the review outcome.
     */
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

    /**
     * A refused approval changes nothing and emails nobody — the "no email"
     * half of the approval guarantee.
     */
    public function test_approval_is_refused_without_a_proof_file_and_notifies_nobody(): void
    {
        Sanctum::actingAs($this->admin());

        $org = $this->organization(); // deliberately no proof file
        $orgAdmin = $this->attachOrgAdmin($org, 'owner@company.com');

        $this->postJson("/api/v1/admin/organizations/{$org->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This organization has no proof document to review. It cannot be approved.');

        $this->assertSame('pending', $org->fresh()->verification_status);
        Notification::assertNothingSentTo($orgAdmin);
    }

    /**
     * `Notification::fake()` intercepts the channel, so a broken mail template
     * would leave this whole suite green while production silently fails to
     * deliver the mail — which is exactly what "the approval email never
     * arrives" looks like from the outside. Rendering both messages with real
     * data is what actually covers the delivery path.
     */
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

        // The reason is optional, so the no-reason variant has to render too.
        $withoutReason = (new OrganizationRejectedNotification($org))->toMail($orgAdmin)->render();
        $this->assertStringContainsString($org->name, $withoutReason);
    }

    /**
     * A dispatched review outcome is logged with the addresses it went to.
     * Without that trace, "the approval email never arrived" cannot be told
     * apart from "there was nobody to send it to".
     */
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

    /**
     * The silent case: the approval succeeds but there is no active admin
     * account, so no mail is attempted. It has to leave a warning behind.
     */
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
