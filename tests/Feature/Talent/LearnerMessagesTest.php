<?php

namespace Tests\Feature\Talent;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\TalentContactRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Company messages: sending to one, sending to all, and the learner's inbox.
 *
 * Three rules this file exists to pin:
 *
 *  1. "Send to all" reaches EXACTLY the people `GET /talent` would show. Not
 *     more — a broadcast must never become the side door around the visibility
 *     model. Not fewer either, or the button silently under-delivers.
 *  2. The learner inbox is READ-ONLY and scoped to the caller. A message the
 *     company sent is visible; another learner's messages are not, and the
 *     company's e-mail is never in the payload.
 *  3. A company may write more than once. The old one-pending-request rule
 *     locked a candidate out of contact forever, because nothing could ever
 *     resolve the pending row.
 */
class LearnerMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    private Role $companyAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->companyAdminRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function organization(string $name = 'Acme'): Organization
    {
        return Organization::forceCreate([
            'name' => $name,
            'type' => 'company',
            'verification_status' => 'verified',
            'contact_email' => strtolower(str_replace(' ', '', $name)).'@example.com',
        ]);
    }

    private function companyAdmin(Organization $organization): User
    {
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
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);

        return $user->fresh();
    }

    private function student(string $name, string $visibility = 'public', bool $consent = true): User
    {
        $user = User::forceCreate([
            'name' => $name,
            'email' => uniqid('student-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->learnerRole->id, ['organization_id' => null]);

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => $visibility,
            'consent_given' => $consent,
        ]);

        return $user->fresh();
    }

    // -----------------------------------------------------------------
    // Send to all
    // -----------------------------------------------------------------

    public function test_a_broadcast_reaches_every_visible_student_and_nobody_else(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        $public = $this->student('Public One');
        $second = $this->student('Public Two');
        $private = $this->student('Private One', 'private');
        $noConsent = $this->student('No Consent', 'public', false);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/talent/contact-all', ['message' => 'We are hiring.'])
            ->assertStatus(201)
            ->assertJsonPath('data.sent_to', 2);

        $reached = TalentContactRequest::pluck('student_id')->sort()->values()->all();

        $this->assertSame(
            collect([$public->id, $second->id])->sort()->values()->all(),
            $reached,
            'a broadcast must reach exactly the students the list would show',
        );

        $this->assertNotContains($private->id, $reached);
        $this->assertNotContains($noConsent->id, $reached);
    }

    public function test_a_broadcast_reaches_the_whole_catalogue_not_just_one_page(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        // More than the 15-row default page size.
        $students = collect(range(1, 20))->map(fn ($i) => $this->student("Student {$i}"));

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/talent/contact-all', ['message' => 'Hello all.'])
            ->assertStatus(201)
            ->assertJsonPath('data.sent_to', 20);

        $this->assertSame(20, TalentContactRequest::count());
        $this->assertSame(
            $students->pluck('id')->sort()->values()->all(),
            TalentContactRequest::pluck('student_id')->sort()->values()->all(),
        );
    }

    public function test_a_company_that_can_see_nobody_sends_to_nobody(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $this->student('Private One', 'private');

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/talent/contact-all', ['message' => 'Anyone?'])
            ->assertStatus(201)
            ->assertJsonPath('data.sent_to', 0);

        $this->assertSame(0, TalentContactRequest::count());
    }

    public function test_a_broadcast_requires_a_message(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $this->student('Public One');

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/talent/contact-all', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_a_pending_organization_cannot_broadcast(): void
    {
        $organization = Organization::forceCreate([
            'name' => 'Pending Co',
            'type' => 'company',
            'verification_status' => 'pending',
            'contact_email' => 'pending@example.com',
        ]);
        $admin = $this->companyAdmin($organization);
        $this->student('Public One');

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/talent/contact-all', ['message' => 'Hello.'])
            ->assertStatus(403);

        $this->assertSame(0, TalentContactRequest::count());
    }

    // -----------------------------------------------------------------
    // The learner's inbox
    // -----------------------------------------------------------------

    public function test_a_learner_sees_the_messages_companies_sent_to_them(): void
    {
        $organization = $this->organization('Acme');
        $admin = $this->companyAdmin($organization);
        $student = $this->student('Reem');

        TalentContactRequest::forceCreate([
            'organization_id' => $organization->id,
            'student_id' => $student->id,
            'requested_by' => $admin->id,
            'message' => 'First message.',
            'status' => TalentContactRequest::STATUS_PENDING,
        ]);
        TalentContactRequest::forceCreate([
            'organization_id' => $organization->id,
            'student_id' => $student->id,
            'requested_by' => $admin->id,
            'message' => 'Second message.',
            'status' => TalentContactRequest::STATUS_PENDING,
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/v1/learner/messages')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            // Newest first: the learner reads the thread from the top.
            ->assertJsonPath('data.0.message', 'Second message.')
            ->assertJsonPath('data.1.message', 'First message.')
            ->assertJsonPath('data.0.from.organization_name', 'Acme')
            ->assertJsonPath('data.0.from.sender_name', 'Company Admin');

        // Privacy: the company's address never travels to the learner.
        $this->assertStringNotContainsString('acme@example.com', $response->getContent());
    }

    public function test_a_learner_never_sees_another_learners_messages(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $mine = $this->student('Mine');
        $theirs = $this->student('Theirs');

        TalentContactRequest::forceCreate([
            'organization_id' => $organization->id,
            'student_id' => $mine->id,
            'requested_by' => $admin->id,
            'message' => 'For me.',
            'status' => TalentContactRequest::STATUS_PENDING,
        ]);
        TalentContactRequest::forceCreate([
            'organization_id' => $organization->id,
            'student_id' => $theirs->id,
            'requested_by' => $admin->id,
            'message' => 'For them.',
            'status' => TalentContactRequest::STATUS_PENDING,
        ]);

        Sanctum::actingAs($mine);

        $this->getJson('/api/v1/learner/messages')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.message', 'For me.');
    }

    public function test_a_learner_with_no_messages_gets_an_empty_list(): void
    {
        $student = $this->student('Quiet');

        Sanctum::actingAs($student);

        $this->getJson('/api/v1/learner/messages')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_a_company_admin_cannot_read_the_learner_inbox(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/learner/messages')->assertStatus(403);
    }

    public function test_the_inbox_requires_authentication(): void
    {
        $this->getJson('/api/v1/learner/messages')->assertStatus(401);
    }
}
