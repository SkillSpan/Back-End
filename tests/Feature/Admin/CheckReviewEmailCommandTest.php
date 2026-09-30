<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\Role;
use App\Models\UploadedFile;
use App\Models\User;
use App\Notifications\OrganizationApprovedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "The approval email never arrives" has three very different causes — the
 * approval was refused before any mail was attempted, there was no active
 * admin account to send to, or the mail went out and the problem is
 * downstream. This command exists to tell them apart without guessing, so the
 * test is that it names the right one in each case.
 */
class CheckReviewEmailCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        Storage::fake('local');
    }

    private function organization(string $status = 'pending'): Organization
    {
        return Organization::forceCreate([
            'name' => 'Test Company',
            'type' => 'company',
            'verification_status' => $status,
            'contact_email' => 'hr@company.com',
        ]);
    }

    private function attachAdmin(Organization $organization, string $email, string $status = 'active'): User
    {
        $user = User::forceCreate([
            'name' => 'Org Admin',
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $organization->members()->attach($user->id, [
            'role_in_org' => 'admin',
            'status' => $status,
        ]);

        return $user;
    }

    private function proofFile(Organization $organization): void
    {
        Storage::disk('local')->put('proofs/proof.pdf', '%PDF-1.4 fake');

        UploadedFile::forceCreate([
            'fileable_type' => Organization::class,
            'fileable_id' => $organization->id,
            'type' => 'certificate',
            'path' => 'proofs/proof.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
            'status' => 'pending',
        ]);
    }

    public function test_it_reports_an_unknown_organization(): void
    {
        $this->artisan('admin:check-review-email', ['organization' => 99])
            ->expectsOutputToContain('No organization with id 99.')
            ->assertExitCode(1);
    }

    /**
     * The refusal happens before any mail is attempted, and it is the only
     * asymmetry between the two review actions — which is why it is the first
     * thing this command checks.
     */
    public function test_it_names_a_missing_proof_document_as_the_blocker(): void
    {
        $organization = $this->organization();
        $this->attachAdmin($organization, 'owner@company.com');

        $this->artisan('admin:check-review-email', ['organization' => $organization->id])
            ->expectsOutputToContain('MISSING')
            ->expectsOutputToContain('Approval would be REFUSED before any email is sent')
            ->assertExitCode(1);
    }

    public function test_it_reports_the_address_that_would_be_notified(): void
    {
        $organization = $this->organization();
        $this->attachAdmin($organization, 'owner@company.com');
        $this->proofFile($organization);

        $this->artisan('admin:check-review-email', ['organization' => $organization->id])
            ->expectsOutputToContain('PRESENT')
            ->expectsOutputToContain('NOTIFIED owner@company.com')
            ->expectsOutputToContain('Approval would proceed and notify: owner@company.com')
            ->assertExitCode(0);
    }

    /**
     * A membership that is no longer active is the silent case: the workflow
     * simply has nobody to email, and nothing in the response says so.
     */
    public function test_it_reports_a_removed_membership_as_a_skipped_recipient(): void
    {
        $organization = $this->organization();
        $this->attachAdmin($organization, 'former@company.com', 'removed');
        $this->proofFile($organization);

        $this->artisan('admin:check-review-email', ['organization' => $organization->id])
            ->expectsOutputToContain('SKIPPED former@company.com')
            ->expectsOutputToContain('No active admin account to notify')
            ->assertExitCode(1);
    }

    public function test_it_flags_an_organization_that_was_already_reviewed(): void
    {
        $organization = $this->organization('verified');
        $this->attachAdmin($organization, 'owner@company.com');
        $this->proofFile($organization);

        $this->artisan('admin:check-review-email', ['organization' => $organization->id])
            ->expectsOutputToContain('already verified')
            ->assertExitCode(0);
    }

    /**
     * Rendering both templates is the check the test suite cannot make while
     * Notification::fake() is active — a template that throws would only ever
     * surface in production.
     */
    public function test_it_renders_both_templates(): void
    {
        $organization = $this->organization();
        $this->attachAdmin($organization, 'owner@company.com');
        $this->proofFile($organization);

        $this->artisan('admin:check-review-email', ['organization' => $organization->id])
            ->expectsOutputToContain('OK     emails.organization-approved')
            ->expectsOutputToContain('OK     emails.organization-rejected')
            ->assertExitCode(0);
    }

    public function test_it_can_send_a_real_email_for_delivery_testing(): void
    {
        Notification::fake();

        $organization = $this->organization();
        $this->attachAdmin($organization, 'owner@company.com');
        $this->proofFile($organization);

        $this->artisan('admin:check-review-email', [
            'organization' => $organization->id,
            '--send-to' => 'verify-me@example.com',
        ])
            ->expectsOutputToContain('Dispatched.')
            ->assertExitCode(0);

        Notification::assertSentOnDemand(OrganizationApprovedNotification::class);
    }
}
