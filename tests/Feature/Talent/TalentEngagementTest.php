<?php

namespace Tests\Feature\Talent;

use App\Models\LearnerSkill;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\TalentContactRequest;
use App\Models\TalentList;
use App\Models\TalentListCandidate;
use App\Models\TalentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Talent engagement: compare, shortlists, notes and contact requests.
 *
 * The rule every one of these tests is really checking is the same: **the
 * company can only act on people it is allowed to see.** A student who is not
 * discoverable must be unreachable through comparison, a shortlist, a note and
 * a contact request — otherwise `GET /talent`'s visibility model would be
 * decorative, and the list would only be hiding what a guessed id reveals.
 *
 * The second rule is privacy: no response in this file may contain the
 * student's e-mail. That is what the contact-request table exists to make
 * defensible.
 */
class TalentEngagementTest extends TestCase
{
    use RefreshDatabase;

    private Role $companyAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyAdminRole = Role::create([
            'name' => 'Company Admin',
            'slug' => 'company_admin',
            'description' => '',
        ]);
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

    /**
     * A discoverable learner: active account, public profile, consent given.
     * Those three are exactly what TalentDiscoveryService requires.
     */
    private function student(string $name, string $visibility = 'public', bool $consent = true): User
    {
        $user = User::forceCreate([
            'name' => $name,
            'email' => uniqid('student-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => $visibility,
            'consent_given' => $consent,
        ]);

        return $user->fresh();
    }

    private function giveSkill(User $student, string $name, float $level): void
    {
        $skill = Skill::firstOrCreate(
            ['slug' => strtolower(str_replace(' ', '-', $name))],
            ['name' => $name, 'category' => 'data'],
        );

        LearnerSkill::create([
            'learner_id' => $student->id,
            'skill_id' => $skill->id,
            'level' => $level,
        ]);
    }

    // -----------------------------------------------------------------
    // Compare
    // -----------------------------------------------------------------

    public function test_two_candidates_can_be_compared(): void
    {
        $admin = $this->companyAdmin($this->organization());

        $first = $this->student('Reem');
        $second = $this->student('Sami');

        $this->giveSkill($first, 'SQL', 4);
        $this->giveSkill($second, 'SQL', 2);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/talent/compare', [
            'student_ids' => [$first->id, $second->id],
        ])->assertOk();

        $this->assertCount(2, $response->json('data.students'));

        // One row per skill, one column per student — the shape a comparison
        // table needs, with the shared skill at the top.
        $sql = collect($response->json('data.skills'))->firstWhere('name', 'SQL');

        $this->assertNotNull($sql);
        $this->assertEquals(4, $sql['levels'][(string) $first->id]);
        $this->assertEquals(2, $sql['levels'][(string) $second->id]);
    }

    public function test_a_student_who_is_not_visible_is_reported_not_leaked(): void
    {
        $admin = $this->companyAdmin($this->organization());

        $visible = $this->student('Reem');
        $other = $this->student('Sami');
        $hidden = $this->student('Hidden', 'private');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/talent/compare', [
            'student_ids' => [$visible->id, $other->id, $hidden->id],
        ])->assertOk();

        $this->assertSame([$hidden->id], $response->json('data.unavailable'));
        $this->assertCount(2, $response->json('data.students'));
    }

    public function test_comparing_fewer_than_two_visible_candidates_is_refused(): void
    {
        $admin = $this->companyAdmin($this->organization());

        $visible = $this->student('Reem');
        $hidden = $this->student('Hidden', 'private');

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/talent/compare', [
            'student_ids' => [$visible->id, $hidden->id],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TALENT_COMPARE_TOO_FEW');
    }

    public function test_more_than_five_candidates_is_a_validation_error(): void
    {
        $admin = $this->companyAdmin($this->organization());

        Sanctum::actingAs($admin);

        $ids = [];
        foreach (range(1, 6) as $i) {
            $ids[] = $this->student("Student {$i}")->id;
        }

        $this->postJson('/api/v1/talent/compare', ['student_ids' => $ids])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    // -----------------------------------------------------------------
    // Shortlists
    // -----------------------------------------------------------------

    public function test_a_list_can_be_created(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/talent/lists', [
            'name' => 'Backend — Spring',
            'description' => 'Shortlist for the API project.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Backend — Spring')
            ->assertJsonPath('data.candidates_count', 0);

        $this->assertSame(1, TalentList::where('organization_id', $organization->id)->count());
    }

    public function test_the_list_index_only_shows_the_callers_organization(): void
    {
        $mine = $this->organization('Acme');
        $theirs = $this->organization('Globex');

        $admin = $this->companyAdmin($mine);
        $otherAdmin = $this->companyAdmin($theirs);

        TalentList::create(['organization_id' => $mine->id, 'created_by' => $admin->id, 'name' => 'Mine']);
        TalentList::create(['organization_id' => $theirs->id, 'created_by' => $otherAdmin->id, 'name' => 'Theirs']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/talent/lists')->assertOk();

        $this->assertSame(['Mine'], collect($response->json('data'))->pluck('name')->all());
    }

    public function test_another_organizations_list_answers_404(): void
    {
        $mine = $this->organization('Acme');
        $theirs = $this->organization('Globex');

        $admin = $this->companyAdmin($mine);
        $otherAdmin = $this->companyAdmin($theirs);

        $foreign = TalentList::create([
            'organization_id' => $theirs->id,
            'created_by' => $otherAdmin->id,
            'name' => 'Theirs',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/talent/lists/{$foreign->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'TALENT_LIST_NOT_FOUND');
    }

    public function test_a_visible_candidate_can_be_added_to_a_list(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $student = $this->student('Reem');

        $list = TalentList::create([
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
            'name' => 'Backend',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/talent/lists/{$list->id}/candidates", [
            'student_id' => $student->id,
            'note' => 'Strong SQL.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.student_id', $student->id)
            ->assertJsonPath('data.already_listed', false);

        $this->assertSame(1, TalentListCandidate::count());
    }

    public function test_adding_the_same_candidate_twice_is_idempotent(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $student = $this->student('Reem');

        $list = TalentList::create([
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
            'name' => 'Backend',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/talent/lists/{$list->id}/candidates", ['student_id' => $student->id])
            ->assertStatus(201);

        // The second press of the button is not an error.
        $this->postJson("/api/v1/talent/lists/{$list->id}/candidates", ['student_id' => $student->id])
            ->assertStatus(200)
            ->assertJsonPath('data.already_listed', true);

        $this->assertSame(1, TalentListCandidate::count());
    }

    public function test_an_invisible_candidate_cannot_be_added_to_a_list(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $hidden = $this->student('Hidden', 'private');

        $list = TalentList::create([
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
            'name' => 'Backend',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/talent/lists/{$list->id}/candidates", ['student_id' => $hidden->id])
            ->assertStatus(404)
            ->assertJsonPath('code', 'TALENT_NOT_FOUND');

        $this->assertSame(0, TalentListCandidate::count());
    }

    public function test_a_list_payload_never_contains_the_student_email(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $student = $this->student('Reem');

        $list = TalentList::create([
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
            'name' => 'Backend',
        ]);

        TalentListCandidate::create([
            'talent_list_id' => $list->id,
            'student_id' => $student->id,
            'added_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $body = $this->getJson("/api/v1/talent/lists/{$list->id}")->assertOk()->getContent();

        // A shortlist is a bookmark, not a hire — the address stays withheld.
        $this->assertStringNotContainsString($student->email, $body);
    }

    // -----------------------------------------------------------------
    // Notes
    // -----------------------------------------------------------------

    public function test_a_note_can_be_written_about_a_visible_candidate(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $student = $this->student('Reem');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/talent/candidates/{$student->id}/notes", [
            'body' => 'Great communicator, needs a second interview.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Great communicator, needs a second interview.')
            ->assertJsonPath('data.author.id', $admin->id);

        $this->assertSame(1, TalentNote::count());
    }

    public function test_notes_are_scoped_to_the_organization(): void
    {
        $mine = $this->organization('Acme');
        $theirs = $this->organization('Globex');

        $admin = $this->companyAdmin($mine);
        $otherAdmin = $this->companyAdmin($theirs);
        $student = $this->student('Reem');

        TalentNote::create([
            'organization_id' => $mine->id,
            'student_id' => $student->id,
            'author_id' => $admin->id,
            'body' => 'Ours.',
        ]);

        TalentNote::create([
            'organization_id' => $theirs->id,
            'student_id' => $student->id,
            'author_id' => $otherAdmin->id,
            'body' => 'Theirs.',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/talent/candidates/{$student->id}/notes")->assertOk();

        $this->assertSame(['Ours.'], collect($response->json('data'))->pluck('body')->all());
    }

    public function test_a_note_cannot_be_written_about_an_invisible_candidate(): void
    {
        $admin = $this->companyAdmin($this->organization());
        $hidden = $this->student('Hidden', 'private');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/talent/candidates/{$hidden->id}/notes", ['body' => 'Should not exist.'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'TALENT_NOT_FOUND');

        $this->assertSame(0, TalentNote::count());
    }

    public function test_an_empty_note_is_a_validation_error(): void
    {
        $admin = $this->companyAdmin($this->organization());
        $student = $this->student('Reem');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/talent/candidates/{$student->id}/notes", ['body' => ''])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    // -----------------------------------------------------------------
    // Contact
    // -----------------------------------------------------------------

    public function test_a_contact_request_can_be_recorded(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $student = $this->student('Reem');

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/talent/candidates/{$student->id}/contact", [
            'message' => 'We would like to discuss a backend internship.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.student_id', $student->id);

        // The address is never returned — the learner decides whether to reply.
        $this->assertStringNotContainsString($student->email, $response->getContent());

        $this->assertSame(1, TalentContactRequest::where('organization_id', $organization->id)->count());
    }

    /**
     * A company may write to the same candidate more than once.
     *
     * It used to be refused with TALENT_CONTACT_PENDING, on the theory that the
     * learner already had an unanswered request. But nothing could ever answer
     * it — there is no learner accept/decline and no company cancel — so the
     * first message locked that candidate out permanently. Now each message is
     * its own row.
     */
    public function test_a_second_message_to_the_same_candidate_is_allowed(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $student = $this->student('Reem');

        TalentContactRequest::create([
            'organization_id' => $organization->id,
            'student_id' => $student->id,
            'requested_by' => $admin->id,
            'message' => 'First.',
            'status' => TalentContactRequest::STATUS_PENDING,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/talent/candidates/{$student->id}/contact", ['message' => 'Second.'])
            ->assertStatus(201)
            ->assertJsonPath('data.message', 'Second.');

        $this->assertSame(2, TalentContactRequest::where('organization_id', $organization->id)->count());
    }

    public function test_a_declined_request_does_not_block_a_new_one(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $student = $this->student('Reem');

        TalentContactRequest::create([
            'organization_id' => $organization->id,
            'student_id' => $student->id,
            'requested_by' => $admin->id,
            'message' => 'First.',
            'status' => TalentContactRequest::STATUS_DECLINED,
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/talent/candidates/{$student->id}/contact", ['message' => 'Again.'])
            ->assertStatus(201);
    }

    public function test_an_invisible_candidate_cannot_be_contacted(): void
    {
        $admin = $this->companyAdmin($this->organization());
        $hidden = $this->student('Hidden', 'private');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/talent/candidates/{$hidden->id}/contact", ['message' => 'Hello?'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'TALENT_NOT_FOUND');

        $this->assertSame(0, TalentContactRequest::count());
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    public function test_a_learner_is_refused(): void
    {
        $learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        $learner = User::forceCreate([
            'name' => 'Learner',
            'email' => 'learner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $learner->roles()->attach($learnerRole->id);

        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/talent/compare', ['student_ids' => [1, 2]])
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMPANY_ADMIN_ONLY');
    }

    public function test_a_pending_organization_is_refused(): void
    {
        $admin = $this->companyAdmin($this->organization('Acme', 'pending'));

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent/lists')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ORGANIZATION_PENDING');
    }

    // -----------------------------------------------------------------
    // Editing and deleting a shortlist
    // -----------------------------------------------------------------

    public function test_a_list_can_be_renamed_without_touching_its_description(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        $list = TalentList::create([
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
            'name' => 'Backend',
            'description' => 'Keep me.',
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/talent/lists/{$list->id}", ['name' => 'Backend — Spring'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Backend — Spring');

        $this->assertSame('Keep me.', $list->fresh()->description);
    }

    public function test_a_list_description_can_be_cleared(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        $list = TalentList::create([
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
            'name' => 'Backend',
            'description' => 'Remove me.',
        ]);

        Sanctum::actingAs($admin);

        // `null` is a deliberate "empty this field", which is different from
        // omitting the key ("leave it alone").
        $this->patchJson("/api/v1/talent/lists/{$list->id}", ['description' => null])
            ->assertOk();

        $this->assertNull($list->fresh()->description);
        $this->assertSame('Backend', $list->fresh()->name);
    }

    public function test_a_patch_with_nothing_in_it_is_a_validation_error(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        $list = TalentList::create([
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
            'name' => 'Backend',
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/talent/lists/{$list->id}", [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_another_organizations_list_cannot_be_edited_or_deleted(): void
    {
        $mine = $this->organization('Acme');
        $theirs = $this->organization('Globex');

        $admin = $this->companyAdmin($mine);
        $otherAdmin = $this->companyAdmin($theirs);

        $foreign = TalentList::create([
            'organization_id' => $theirs->id,
            'created_by' => $otherAdmin->id,
            'name' => 'Theirs',
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/talent/lists/{$foreign->id}", ['name' => 'Mine now'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'TALENT_LIST_NOT_FOUND');

        $this->deleteJson("/api/v1/talent/lists/{$foreign->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'TALENT_LIST_NOT_FOUND');

        $this->assertSame('Theirs', $foreign->fresh()->name);
    }

    public function test_deleting_a_list_removes_the_rows_not_the_people(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $student = $this->student('Reem');

        $list = TalentList::create([
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
            'name' => 'Backend',
        ]);

        TalentListCandidate::create([
            'talent_list_id' => $list->id,
            'student_id' => $student->id,
            'added_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/talent/lists/{$list->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertSame(0, TalentList::whereKey($list->id)->count());
        $this->assertSame(0, TalentListCandidate::where('talent_list_id', $list->id)->count());

        // Deleting a shortlist must never delete the candidate.
        $this->assertSame(1, User::whereKey($student->id)->count());
    }

    public function test_a_candidate_can_be_removed_from_a_list(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $student = $this->student('Reem');

        $list = TalentList::create([
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
            'name' => 'Backend',
        ]);

        TalentListCandidate::create([
            'talent_list_id' => $list->id,
            'student_id' => $student->id,
            'added_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/talent/lists/{$list->id}/candidates/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.removed', true);

        $this->assertSame(0, TalentListCandidate::count());
        $this->assertSame(1, TalentList::whereKey($list->id)->count());
    }

    public function test_removing_somebody_who_is_not_on_the_list_is_404(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $student = $this->student('Reem');

        $list = TalentList::create([
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
            'name' => 'Backend',
        ]);

        Sanctum::actingAs($admin);

        // Not a silent 200: the row the caller is looking at should exist, so
        // "it worked" would hide a stale screen.
        $this->deleteJson("/api/v1/talent/lists/{$list->id}/candidates/{$student->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'TALENT_LIST_CANDIDATE_NOT_FOUND');
    }

    // -----------------------------------------------------------------
    // Funnel
    // -----------------------------------------------------------------

    public function test_the_funnel_counts_people_not_rows(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $student = $this->student('Reem');

        // The same person on two shortlists is one saved candidate, otherwise
        // tidying your lists would inflate the funnel.
        foreach (['Backend', 'Fallback'] as $name) {
            $list = TalentList::create([
                'organization_id' => $organization->id,
                'created_by' => $admin->id,
                'name' => $name,
            ]);

            TalentListCandidate::create([
                'talent_list_id' => $list->id,
                'student_id' => $student->id,
                'added_by' => $admin->id,
            ]);
        }

        TalentContactRequest::create([
            'organization_id' => $organization->id,
            'student_id' => $student->id,
            'requested_by' => $admin->id,
            'message' => 'Hello.',
            'status' => TalentContactRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent/funnel')
            ->assertOk()
            ->assertJsonPath('data.saved', 1)
            ->assertJsonPath('data.contacted', 1)
            ->assertJsonPath('data.responded', 1);
    }

    public function test_a_declined_request_counts_as_contacted_but_not_responded(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $student = $this->student('Reem');

        TalentContactRequest::create([
            'organization_id' => $organization->id,
            'student_id' => $student->id,
            'requested_by' => $admin->id,
            'message' => 'Hello.',
            'status' => TalentContactRequest::STATUS_DECLINED,
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        // A decline is an answer, but a funnel that counts it cannot be read
        // as progress.
        $this->getJson('/api/v1/talent/funnel')
            ->assertOk()
            ->assertJsonPath('data.contacted', 1)
            ->assertJsonPath('data.responded', 0);
    }

    public function test_the_funnel_only_counts_the_callers_organization(): void
    {
        $mine = $this->organization('Acme');
        $theirs = $this->organization('Globex');

        $admin = $this->companyAdmin($mine);
        $otherAdmin = $this->companyAdmin($theirs);
        $student = $this->student('Reem');

        $foreignList = TalentList::create([
            'organization_id' => $theirs->id,
            'created_by' => $otherAdmin->id,
            'name' => 'Theirs',
        ]);

        TalentListCandidate::create([
            'talent_list_id' => $foreignList->id,
            'student_id' => $student->id,
            'added_by' => $otherAdmin->id,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent/funnel')
            ->assertOk()
            ->assertJsonPath('data.saved', 0)
            ->assertJsonPath('data.contacted', 0)
            ->assertJsonPath('data.responded', 0);
    }

    public function test_the_funnel_refuses_a_learner(): void
    {
        $learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        $learner = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid('learner-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $learner->roles()->attach($learnerRole->id);

        Sanctum::actingAs($learner->fresh());

        $this->getJson('/api/v1/talent/funnel')
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMPANY_ADMIN_ONLY');
    }
}
