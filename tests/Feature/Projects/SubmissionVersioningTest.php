<?php

namespace Tests\Feature\Projects;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubmissionVersioningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private User $outsider;

    private Project $project;

    private ProjectMilestone $milestone;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        foreach (['learner', 'admin', 'company_admin'] as $slug) {
            Role::create(['name' => ucfirst($slug), 'slug' => $slug, 'description' => '']);
        }

        $organization = Organization::forceCreate([
            'name' => 'Submission Org',
            'type' => 'company',
            'verification_status' => 'verified',
        ]);

        $this->owner = $this->user('owner@test.com', 'company_admin', $organization);
        $this->member = $this->user('member@test.com', 'learner');
        $this->outsider = $this->user('outsider@test.com', 'learner');

        $this->project = Project::forceCreate([
            'organization_id' => $organization->id,
            'owner_id' => $this->owner->id,
            'type' => 'company_sponsored',
            'title' => 'Submission Project',
            'status' => Project::STATUS_ACTIVE,
            'confidentiality' => 'public',
        ]);

        $team = ProjectTeam::create(['project_id' => $this->project->id, 'name' => 'Team']);
        ProjectTeamMember::forceCreate([
            'project_team_id' => $team->id,
            'user_id' => $this->member->id,
            'project_role' => 'backend_developer',
            'assignment_state' => 'active',
        ]);

        $this->milestone = ProjectMilestone::create([
            'project_id' => $this->project->id,
            'title' => 'Milestone one',
            'required_deliverables' => ['Repository link'],
            'submission_type' => 'any',
            'due_date' => now()->addDays(5)->toDateString(),
            'effective_deadline' => now()->addDays(5)->toDateString(),
            'status' => ProjectMilestone::STATUS_PENDING,
            'order_index' => 0,
        ]);
    }

    private function user(string $email, string $role, ?Organization $organization = null): User
    {
        $user = User::forceCreate([
            'name' => 'User '.$email,
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', $role)->first()->id);

        if ($organization !== null) {
            $user->organizations()->attach($organization->id, ['role_in_org' => 'admin', 'status' => 'active']);
        }

        return $user->fresh();
    }

    private function submitLink(array $overrides = [])
    {
        return $this->postJson("/api/v1/projects/{$this->project->id}/submissions", array_merge([
            'submission_type' => 'repository_link',
            'project_milestone_id' => $this->milestone->id,
            'link_url' => 'https://github.com/skillspan/delivery',
            'notes' => 'First delivery.',
        ], $overrides));
    }

    public function test_a_member_submits_a_repository_link(): void
    {
        Sanctum::actingAs($this->member);

        $this->submitLink()
            ->assertStatus(201)
            ->assertJsonPath('data.submission.version', 1)
            ->assertJsonPath('data.submission.is_current', true)
            ->assertJsonPath('data.submission.first_pass.status', 'passed')
            ->assertJsonPath('data.replayed', false);

        $this->assertSame(
            ProjectMilestone::STATUS_SUBMITTED,
            $this->milestone->fresh()->status,
            'A milestone with a submission must move to submitted.'
        );
    }

    public function test_a_non_member_cannot_submit(): void
    {
        Sanctum::actingAs($this->outsider);

        $this->submitLink()
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_WORKSPACE_FORBIDDEN');
    }

    public function test_submission_is_blocked_after_the_effective_deadline(): void
    {
        Sanctum::actingAs($this->member);

        $this->milestone->forceFill([
            'due_date' => now()->subDays(2)->toDateString(),
            'effective_deadline' => now()->subDays(2)->toDateString(),
        ])->save();

        $this->submitLink()
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUBMISSION_DEADLINE_PASSED');
    }

    public function test_an_approved_extension_reopens_the_submission_window(): void
    {
        Sanctum::actingAs($this->member);

        $this->milestone->forceFill([
            'due_date' => now()->subDays(2)->toDateString(),
            'effective_deadline' => now()->addDays(4)->toDateString(),
        ])->save();

        $this->submitLink()->assertStatus(201);
    }

    public function test_replacing_a_submission_creates_a_new_version_and_keeps_the_old_one(): void
    {
        Sanctum::actingAs($this->member);

        $firstId = $this->submitLink()->assertStatus(201)->json('data.submission.id');

        $this->submitLink([
            'link_url' => 'https://github.com/skillspan/delivery-v2',
            'notes' => 'Second delivery.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.submission.version', 2)
            ->assertJsonPath('data.submission.is_current', true);

        $this->assertSame(2, Submission::count());

        $first = Submission::findOrFail($firstId);
        $this->assertFalse((bool) $first->is_current);
        $this->assertSame(Submission::STATUS_SUPERSEDED, $first->status);
    }

    public function test_a_retried_request_with_the_same_idempotency_key_does_not_create_a_second_version(): void
    {
        Sanctum::actingAs($this->member);

        $payload = ['idempotency_key' => 'retry-key-1'];

        $first = $this->submitLink($payload)->assertStatus(201)->json('data.submission.id');

        $this->submitLink($payload)
            ->assertStatus(200)
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.submission.id', $first);

        $this->assertSame(1, Submission::count());
    }

    public function test_an_approved_compressed_file_is_stored(): void
    {
        Sanctum::actingAs($this->member);

        $file = UploadedFile::fake()->create('delivery.zip', 120, 'application/zip');

        $response = $this->postJson("/api/v1/projects/{$this->project->id}/submissions", [
            'submission_type' => 'compressed_file',
            'project_milestone_id' => $this->milestone->id,
            'notes' => 'Zipped delivery.',
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.submission.submission_type', 'compressed_file')
            ->assertJsonPath('data.submission.file.name', 'delivery.zip');

        $stored = Submission::findOrFail($response->json('data.submission.id'));
        Storage::disk('local')->assertExists((string) $stored->file_path);
    }

    public function test_an_unsupported_file_type_is_rejected_without_creating_a_submission(): void
    {
        Sanctum::actingAs($this->member);

        $file = UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload');

        $this->postJson("/api/v1/projects/{$this->project->id}/submissions", [
            'submission_type' => 'compressed_file',
            'project_milestone_id' => $this->milestone->id,
            'file' => $file,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUBMISSION_FILE_TYPE_NOT_ALLOWED');

        $this->assertSame(0, Submission::count());
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        Sanctum::actingAs($this->member);

        $file = UploadedFile::fake()->create('huge.zip', 40960, 'application/zip');

        $this->postJson("/api/v1/projects/{$this->project->id}/submissions", [
            'submission_type' => 'compressed_file',
            'project_milestone_id' => $this->milestone->id,
            'file' => $file,
        ])->assertStatus(422);

        $this->assertSame(0, Submission::count());
    }

    public function test_an_unsafe_filename_is_sanitized(): void
    {
        Sanctum::actingAs($this->member);

        $file = UploadedFile::fake()->create('../../evil script.zip', 20, 'application/zip');

        $response = $this->postJson("/api/v1/projects/{$this->project->id}/submissions", [
            'submission_type' => 'compressed_file',
            'project_milestone_id' => $this->milestone->id,
            'file' => $file,
        ])->assertStatus(201);

        $stored = Submission::findOrFail($response->json('data.submission.id'));

        $this->assertSame('evil_script.zip', $stored->original_filename);
        $this->assertStringNotContainsString('..', (string) $stored->file_path);
    }

    public function test_a_milestone_submission_type_is_enforced(): void
    {
        Sanctum::actingAs($this->member);

        $this->milestone->forceFill(['submission_type' => 'compressed_file'])->save();

        $this->submitLink()
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUBMISSION_TYPE_NOT_ALLOWED');
    }

    public function test_the_history_marks_the_current_version(): void
    {
        Sanctum::actingAs($this->member);

        $this->submitLink()->assertStatus(201);
        $this->submitLink(['link_url' => 'https://github.com/skillspan/delivery-v2'])->assertStatus(201);

        $response = $this->getJson("/api/v1/projects/{$this->project->id}/submissions");

        $response->assertStatus(200)->assertJsonCount(2, 'data.submissions');

        $current = collect($response->json('data.submissions'))->where('is_current', true);
        $this->assertCount(1, $current);
        $this->assertSame(2, $current->first()['version']);
    }

    public function test_the_first_pass_check_endpoint_returns_the_check_items(): void
    {
        Sanctum::actingAs($this->member);

        $id = $this->submitLink()->assertStatus(201)->json('data.submission.id');

        $this->getJson("/api/v1/submissions/{$id}/first-pass-check")
            ->assertStatus(200)
            ->assertJsonPath('data.first_pass_check.status', 'passed')
            ->assertJsonCount(4, 'data.first_pass_check.items');
    }

    public function test_a_milestone_cannot_receive_submissions_after_completion(): void
    {
        Sanctum::actingAs($this->member);

        $this->milestone->forceFill(['status' => ProjectMilestone::STATUS_COMPLETED])->save();

        $this->submitLink()
            ->assertStatus(422)
            ->assertJsonPath('code', 'MILESTONE_COMPLETED');
    }
}
