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
     */
    private function attachOrgAdmin(Organization $organization): User
    {
        $member = User::forceCreate([
            'name' => 'Company Admin',
            'email' => uniqid().'@company.com',
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
}
