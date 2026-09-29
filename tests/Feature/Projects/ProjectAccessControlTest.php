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

        // The recommendation row survives, but the project payload is withheld.
        $response = $this->getJson('/api/v1/recommendations');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.project_id', $project->id)
            ->assertJsonPath('data.0.project', null)
            ->assertJsonPath('data.0.reasons', 'Stored reason.');

        $this->assertDatabaseCount('recommendations', 1);
        $this->assertStringNotContainsString('Access control fixture', $response->getContent());
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
}
