<?php

namespace Tests\Feature\Projects;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Project access control: organization membership status, restricted-project
 * visibility, and the project payload attached to stored recommendations.
 *
 * Regression origin: ProjectController resolved the learner's organization with
 *   DB::table('organization_members')->where('user_id', $id)->value('organization_id')
 * which ignored `status` entirely. `organization_members.status` is
 * enum('active','invited','removed') defaulting to 'active', so a REMOVED (or
 * merely invited) member still resolved to the organization and could read its
 * restricted projects — while ProjectMatchingSnapshotService::isAuthorized
 * required wherePivot('status','active'). Discovery was strictly more
 * permissive than matching.
 */
class ProjectAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    // ------------------------------------------------------------ fixtures

    private function createLearner(string $email = 'access@test.com'): User
    {
        $user = User::forceCreate([
            'name' => 'Access Learner',
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        return $user->load('studentProfile');
    }

    private function joinOrganization(User $learner, Organization $organization, string $status): void
    {
        $learner->organizations()->attach($organization->id, [
            'role_in_org' => 'member',
            'status' => $status,
        ]);
    }

    private function createOrganization(string $name): Organization
    {
        return Organization::create(['name' => $name, 'type' => 'company']);
    }

    private function createProject(Organization $organization, array $attributes = []): Project
    {
        return Project::create(array_merge([
            'organization_id' => $organization->id,
            'owner_id' => User::first()?->id,
            'title' => 'Access Project',
            'description' => 'Access control fixture',
            'type' => 'company_sponsored',
            'status' => 'open',
            'confidentiality' => 'public',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ], $attributes));
    }

    private function storedRecommendation(User $learner, Project $project, float $score = 70.0): Recommendation
    {
        return Recommendation::create([
            'user_id' => $learner->id,
            'type' => 'project',
            'candidate_type' => 'project',
            'candidate_id' => $project->id,
            'score' => $score,
            'reasons' => 'Stored reason.',
            'algorithm_version' => 'project-matching-v1',
            'configuration_version' => 'project-matching-config-v1',
            'eligibility_state' => 'eligible',
            'matching_state' => 'scored',
            'generated_at' => now(),
        ]);
    }

    // ------------------------------------------- 3.1 organization membership

    public function test_active_member_sees_the_organizations_restricted_project(): void
    {
        $organization = $this->createOrganization('Active Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'active');

        $project = $this->createProject($organization, ['confidentiality' => 'restricted']);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $project->id);
    }

    public function test_removed_member_cannot_see_the_organizations_restricted_project(): void
    {
        $organization = $this->createOrganization('Removed Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'removed');

        $this->createProject($organization, ['confidentiality' => 'restricted']);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects')
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_invited_but_unconfirmed_member_cannot_see_the_restricted_project(): void
    {
        $organization = $this->createOrganization('Invited Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'invited');

        $this->createProject($organization, ['confidentiality' => 'restricted']);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects')
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_member_of_another_organization_cannot_see_the_restricted_project(): void
    {
        $ownOrganization = $this->createOrganization('Own Org');
        $otherOrganization = $this->createOrganization('Other Org');

        $learner = $this->createLearner();
        $this->joinOrganization($learner, $ownOrganization, 'active');

        $this->createProject($otherOrganization, ['confidentiality' => 'restricted']);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects')
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_learner_without_any_membership_still_sees_public_projects(): void
    {
        $organization = $this->createOrganization('Public Org');
        $learner = $this->createLearner();

        $project = $this->createProject($organization, ['confidentiality' => 'public']);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $project->id);
    }

    public function test_learner_without_any_membership_cannot_see_restricted_projects(): void
    {
        $organization = $this->createOrganization('Restricted Org');
        $learner = $this->createLearner();

        $this->createProject($organization, ['confidentiality' => 'restricted']);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects')
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_project_details_are_rejected_once_membership_is_removed(): void
    {
        $organization = $this->createOrganization('Detail Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'active');

        $project = $this->createProject($organization, ['confidentiality' => 'restricted']);

        Sanctum::actingAs($learner);
        $this->getJson('/api/v1/projects/'.$project->id)->assertStatus(200);

        // Membership is revoked.
        $learner->organizations()->updateExistingPivot($organization->id, ['status' => 'removed']);

        Sanctum::actingAs($learner->fresh());
        $this->getJson('/api/v1/projects/'.$project->id)->assertStatus(403);
    }

    public function test_multi_organization_member_sees_both_organizations_restricted_projects(): void
    {
        $first = $this->createOrganization('First Org');
        $second = $this->createOrganization('Second Org');

        $learner = $this->createLearner();
        $this->joinOrganization($learner, $first, 'active');
        $this->joinOrganization($learner, $second, 'active');

        $firstProject = $this->createProject($first, ['confidentiality' => 'restricted', 'title' => 'First']);
        $secondProject = $this->createProject($second, ['confidentiality' => 'restricted', 'title' => 'Second']);

        Sanctum::actingAs($learner);

        $ids = collect($this->getJson('/api/v1/projects')->assertStatus(200)->json('data'))
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            collect([$firstProject->id, $secondProject->id])->sort()->values()->all(),
            $ids,
        );
    }

    // --------------------------------- 3.2 recommendation list project payload

    public function test_recommendation_list_keeps_the_record_but_hides_a_project_once_access_is_lost(): void
    {
        $organization = $this->createOrganization('Lost Access Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'active');

        $project = $this->createProject($organization, ['confidentiality' => 'restricted']);
        $this->storedRecommendation($learner, $project);

        // While access holds, the project payload is present.
        Sanctum::actingAs($learner);
        $this->getJson('/api/v1/recommendations')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.project.id', $project->id);

        // Membership is revoked.
        $learner->organizations()->updateExistingPivot($organization->id, ['status' => 'removed']);

        // The recommendation row survives — and the WHOLE result is redacted,
        // not just the project payload. Nulling only `project` still leaked
        // score, reasons, factors and every version field.
        $response = $this->getJson('/api/v1/recommendations');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.project_id', $project->id)
            ->assertJsonPath('data.0.project', null)
            ->assertJsonPath('data.0.access_revoked', true)
            ->assertJsonPath('data.0.reasons', null)
            ->assertJsonPath('data.0.score', null)
            ->assertJsonPath('data.0.algorithm_version', null);

        $this->assertDatabaseCount('recommendations', 1);
        $this->assertStringNotContainsString('Access control fixture', $response->getContent());
        $this->assertStringNotContainsString('Stored reason.', $response->getContent());
    }

    public function test_recommendation_list_hides_a_closed_projects_payload_but_keeps_the_record(): void
    {
        $organization = $this->createOrganization('Closed Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'active');

        $project = $this->createProject($organization, ['confidentiality' => 'public']);
        $this->storedRecommendation($learner, $project);

        $project->update(['status' => 'closed']);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/recommendations')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.project', null);

        $this->assertDatabaseCount('recommendations', 1);
    }

    // ------------------------- 3. recommendation DETAILS authorization (Task 12)

    public function test_authorized_learner_can_retrieve_recommendation_details(): void
    {
        $organization = $this->createOrganization('Details Authorized Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'active');

        $project = $this->createProject($organization, ['confidentiality' => 'restricted']);
        $this->storedRecommendation($learner, $project, 70.0);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects/'.$project->id.'/recommendation')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.reasons', 'Stored reason.')
            ->assertJsonPath('data.algorithm_version', 'project-matching-v1')
            ->assertJsonPath('data.configuration_version', 'project-matching-config-v1');
    }

    public function test_recommendation_details_are_withheld_when_active_membership_is_removed(): void
    {
        // REGRESSION: showForProject() only checked that the project EXISTED,
        // never that the learner could still access it, so score/reasons/
        // limiting factors stayed readable after access was revoked.
        $organization = $this->createOrganization('Details Revoked Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'active');

        $project = $this->createProject($organization, ['confidentiality' => 'restricted']);
        $this->storedRecommendation($learner, $project);

        // Access holds: details are returned.
        Sanctum::actingAs($learner);
        $this->getJson('/api/v1/projects/'.$project->id.'/recommendation')
            ->assertStatus(200)
            ->assertJsonPath('data.score', 70);

        // Membership revoked.
        $learner->organizations()->updateExistingPivot($organization->id, ['status' => 'removed']);
        Sanctum::actingAs($learner->fresh());

        $response = $this->getJson('/api/v1/projects/'.$project->id.'/recommendation');

        $response->assertStatus(404);

        // No sensitive recommendation field leaks.
        $this->assertNull($response->json('data'));
        $this->assertStringNotContainsString('Stored reason.', $response->getContent());
        $this->assertStringNotContainsString('project-matching-v1', $response->getContent());

        // The stored row is preserved, not deleted.
        $this->assertDatabaseCount('recommendations', 1);
    }

    public function test_invited_member_cannot_retrieve_restricted_recommendation_details(): void
    {
        $organization = $this->createOrganization('Details Invited Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'invited');

        $project = $this->createProject($organization, ['confidentiality' => 'restricted']);
        $this->storedRecommendation($learner, $project);

        Sanctum::actingAs($learner);

        $response = $this->getJson('/api/v1/projects/'.$project->id.'/recommendation');

        $response->assertStatus(404);
        $this->assertNull($response->json('data'));
        $this->assertStringNotContainsString('Stored reason.', $response->getContent());
    }

    public function test_removed_member_cannot_retrieve_restricted_recommendation_details(): void
    {
        $organization = $this->createOrganization('Details Removed Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'removed');

        $project = $this->createProject($organization, ['confidentiality' => 'restricted']);
        $this->storedRecommendation($learner, $project);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects/'.$project->id.'/recommendation')
            ->assertStatus(404)
            ->assertJsonPath('data', null);
    }

    public function test_details_are_withheld_when_the_project_becomes_closed(): void
    {
        $organization = $this->createOrganization('Details Closed Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'active');

        $project = $this->createProject($organization, ['confidentiality' => 'public']);
        $this->storedRecommendation($learner, $project);

        $project->update(['status' => 'closed']);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects/'.$project->id.'/recommendation')
            ->assertStatus(404);

        $this->assertDatabaseCount('recommendations', 1);
    }

    public function test_details_are_withheld_once_the_application_deadline_passes(): void
    {
        $organization = $this->createOrganization('Details Expired Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'active');

        $project = $this->createProject($organization, ['confidentiality' => 'public']);
        $this->storedRecommendation($learner, $project);

        $project->update(['application_deadline' => now()->subDay()->toDateString()]);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects/'.$project->id.'/recommendation')
            ->assertStatus(404);
    }

    public function test_inaccessible_and_nonexistent_projects_are_indistinguishable(): void
    {
        // Non-disclosure: the endpoint must not let a caller work out whether a
        // restricted project exists by comparing the two responses.
        $otherOrganization = $this->createOrganization('Details Other Org');
        $learner = $this->createLearner();

        $restricted = $this->createProject($otherOrganization, ['confidentiality' => 'restricted']);
        $this->storedRecommendation($learner, $restricted);

        Sanctum::actingAs($learner);

        $inaccessible = $this->getJson('/api/v1/projects/'.$restricted->id.'/recommendation');
        $nonexistent = $this->getJson('/api/v1/projects/999999/recommendation');

        $inaccessible->assertStatus(404);
        $nonexistent->assertStatus(404);

        $this->assertSame($nonexistent->json('code'), $inaccessible->json('code'));
        $this->assertSame($nonexistent->json('message'), $inaccessible->json('message'));

        // `details` echoes the id the caller themselves supplied, so the values
        // differ by construction — that reveals nothing. What matters is that
        // the shape is identical and no project content is attached.
        $this->assertSame(
            array_keys($nonexistent->json('details')),
            array_keys($inaccessible->json('details')),
        );
        $this->assertSame($restricted->id, $inaccessible->json('details.project_id'));
        $this->assertSame(999999, $nonexistent->json('details.project_id'));

        // Neither response carries the stored recommendation.
        $this->assertNull($inaccessible->json('data'));
        $this->assertStringNotContainsString('Stored reason.', $inaccessible->getContent());
    }

    // --------------- 3. recommendation LIST redaction when access is revoked

    public function test_recommendation_list_redacts_all_details_when_access_is_lost(): void
    {
        // REGRESSION: index() nulled only the embedded `project`, while the
        // resource kept returning score, reasons, limiting_factors, factors,
        // weighted_contributions, skill_results and every version field — so an
        // inaccessible project's matching outcome stayed fully readable.
        $organization = $this->createOrganization('Redact Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'active');

        $project = $this->createProject($organization, ['confidentiality' => 'restricted']);
        $this->storedRecommendation($learner, $project, 88.0);

        // While access holds, everything is returned.
        Sanctum::actingAs($learner);
        $this->getJson('/api/v1/recommendations')
            ->assertStatus(200)
            ->assertJsonPath('data.0.score', 88)
            ->assertJsonPath('data.0.reasons', 'Stored reason.')
            ->assertJsonPath('data.0.project.id', $project->id)
            ->assertJsonPath('data.0.access_revoked', false);

        // Membership revoked.
        $learner->organizations()->updateExistingPivot($organization->id, ['status' => 'removed']);
        Sanctum::actingAs($learner->fresh());

        $response = $this->getJson('/api/v1/recommendations');
        $response->assertStatus(200)->assertJsonPath('meta.total', 1);

        $row = $response->json('data.0');

        // The historical record is still identifiable...
        $this->assertSame($project->id, $row['project_id']);
        $this->assertSame('project', $row['type']);
        $this->assertNotNull($row['generated_at']);
        $this->assertTrue($row['access_revoked']);

        // ...but every sensitive field is redacted.
        foreach ([
            'project',
            'score',
            'eligibility_state',
            'matching_state',
            'reasons',
            'limiting_factors',
            'factors',
            'weighted_contributions',
            'skill_results',
            'algorithm_version',
            'configuration_version',
            'project_version',
        ] as $field) {
            $this->assertNull($row[$field], "Field [{$field}] must be redacted.");
        }

        // Nothing sensitive survives in the raw body either.
        $this->assertStringNotContainsString('Stored reason.', $response->getContent());
        $this->assertStringNotContainsString('project-matching-v1', $response->getContent());

        // The stored row is preserved — only its exposure stopped.
        $this->assertDatabaseCount('recommendations', 1);
    }

    public function test_accessible_recommendation_is_not_redacted(): void
    {
        $organization = $this->createOrganization('Not Redacted Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'active');

        $project = $this->createProject($organization, ['confidentiality' => 'restricted']);
        $this->storedRecommendation($learner, $project, 55.0);

        Sanctum::actingAs($learner);

        $row = $this->getJson('/api/v1/recommendations')->assertStatus(200)->json('data.0');

        $this->assertFalse($row['access_revoked']);
        $this->assertEquals(55.0, $row['score']);
        $this->assertSame('Stored reason.', $row['reasons']);
        $this->assertSame('project-matching-v1', $row['algorithm_version']);
        $this->assertSame('project-matching-config-v1', $row['configuration_version']);
        $this->assertSame($project->id, $row['project']['id']);
    }

    public function test_redaction_applies_when_the_project_becomes_closed(): void
    {
        $organization = $this->createOrganization('Redact Closed Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'active');

        $project = $this->createProject($organization, ['confidentiality' => 'public']);
        $this->storedRecommendation($learner, $project, 91.0);

        $project->update(['status' => 'closed']);

        Sanctum::actingAs($learner);

        $row = $this->getJson('/api/v1/recommendations')->assertStatus(200)->json('data.0');

        $this->assertTrue($row['access_revoked']);
        $this->assertNull($row['score']);
        $this->assertNull($row['reasons']);
        $this->assertNull($row['project']);
    }

    public function test_redaction_applies_when_the_project_has_been_deleted(): void
    {
        $organization = $this->createOrganization('Redact Deleted Org');
        $learner = $this->createLearner();
        $this->joinOrganization($learner, $organization, 'active');

        $project = $this->createProject($organization, ['confidentiality' => 'public']);
        $this->storedRecommendation($learner, $project, 77.0);

        $project->delete();

        Sanctum::actingAs($learner);

        $row = $this->getJson('/api/v1/recommendations')->assertStatus(200)->json('data.0');

        $this->assertTrue($row['access_revoked']);
        $this->assertNull($row['score']);
        $this->assertNull($row['project']);
        $this->assertDatabaseCount('recommendations', 1);
    }
}
