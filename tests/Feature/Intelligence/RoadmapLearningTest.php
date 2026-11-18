<?php

namespace Tests\Feature\Intelligence;

use App\Models\CareerRole;
use App\Models\LearningActivity;
use App\Models\LearningResource;
use App\Models\Roadmap;
use App\Models\RoadmapAction;
use App\Models\Role;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoadmapLearningTest extends TestCase
{
    use RefreshDatabase;

    private User $learner;

    private User $admin;

    private RoadmapAction $action;

    private LearningResource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['learner', 'admin'] as $slug) {
            $role = Role::create(['name' => $slug, 'slug' => $slug]);
            $user = User::forceCreate(['name' => $slug, 'email' => $slug.'@example.test',
                'password' => 'password123', 'status' => 'active', 'email_verified_at' => now()]);
            $user->roles()->attach($role);
            $this->{$slug} = $user;
        }
        $profile = StudentProfile::forceCreate(['user_id' => $this->learner->id]);
        $career = CareerRole::forceCreate(['title' => 'Data Analyst', 'slug' => 'data-analyst', 'version' => 1]);
        $skill = Skill::forceCreate(['name' => 'SQL', 'slug' => 'sql', 'status' => 'active', 'version' => 1]);
        $roadmap = Roadmap::create(['student_profile_id' => $profile->id, 'career_role_id' => $career->id,
            'career_role_version' => 1, 'version' => 1, 'status' => 'active', 'generated_at' => now()]);
        $this->action = RoadmapAction::create(['roadmap_id' => $roadmap->id, 'phase' => 'foundations',
            'type' => 'resource', 'target_skill_id' => $skill->id, 'title' => 'Learn SQL']);
        $this->resource = LearningResource::forceCreate(['title' => 'SQL tutorial', 'provider' => 'PostgreSQL',
            'url' => 'https://www.postgresql.org/docs/current/tutorial-sql.html', 'skill_id' => $skill->id,
            'cost' => 0, 'level' => 1, 'language' => 'en', 'prerequisites' => 'Basic computer skills',
            'status' => 'active', 'approved_by' => $this->admin->id, 'approved_at' => now(), 'validated_at' => now()]);
        Sanctum::actingAs($this->learner);
    }

    private function start()
    {
        return $this->postJson('/api/v1/roadmap-actions/'.$this->action->id.'/start',
            ['learning_resource_id' => $this->resource->id]);
    }

    public function test_duplicate_start_records_one_activity_without_completing_or_scoring(): void
    {
        $this->start()->assertOk()->assertJsonPath('data.activity.progress_value', 0);
        $this->start()->assertOk();
        $this->assertDatabaseCount('learning_activities', 1);
        $this->assertDatabaseHas('roadmap_actions', ['id' => $this->action->id, 'status' => 'in_progress', 'completed_at' => null]);
        $this->assertDatabaseCount('skill_evaluations', 0);
    }

    public function test_only_fresh_approved_free_safe_resources_are_listed_and_startable(): void
    {
        foreach ([['approved_at' => null], ['status' => 'inactive'], ['validated_at' => now()->subDays(91)],
            ['validated_at' => now()->addDay()], ['cost' => 10], ['level' => 5], ['url' => 'javascript:alert(1)'], ['skill_id' => null]] as $changes) {
            $original = $this->resource->getAttributes();
            $this->resource->forceFill($changes)->save();
            $this->getJson('/api/v1/roadmap-actions/'.$this->action->id.'/resources')->assertOk()->assertJsonCount(0, 'data.resources');
            $this->start()->assertUnprocessable();
            $this->resource->setRawAttributes($original);
            $this->resource->save();
        }
        $this->assertDatabaseCount('learning_activities', 0);
    }

    public function test_other_user_and_blocked_or_superseded_actions_cannot_start(): void
    {
        Sanctum::actingAs($this->admin);
        $this->start()->assertForbidden();
        $other = User::forceCreate(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'password123', 'status' => 'active']);
        $other->roles()->attach(Role::where('slug', 'learner')->first());
        Sanctum::actingAs($other);
        $this->start()->assertNotFound();
        Sanctum::actingAs($this->learner);
        $this->action->forceFill(['status' => 'blocked'])->save();
        $this->start()->assertConflict();
        $this->action->forceFill(['status' => 'not_started'])->save();
        $this->action->roadmap->update(['status' => 'superseded']);
        $this->start()->assertConflict();
    }

    public function test_completion_needs_evidence_and_admin_review_and_never_creates_a_skill_score(): void
    {
        $this->start()->assertOk();
        $path = '/api/v1/roadmap-actions/'.$this->action->id.'/progress';
        $this->patchJson($path, ['progress_value' => 101])->assertUnprocessable();
        $this->patchJson($path, ['progress_value' => 100])->assertUnprocessable();
        $this->patchJson($path, ['progress_value' => 100, 'completion_evidence' => 'SQL query and explanation ready for review'])
            ->assertOk()->assertJsonPath('data.verification_state', 'pending');
        $this->assertSame('in_progress', $this->action->fresh()->status);
        $activity = LearningActivity::first();
        $review = '/api/v1/admin/learning-activities/'.$activity->id.'/review';
        $this->postJson($review, ['accepted' => true, 'notes' => 'Reviewed'])->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->postJson($review, ['accepted' => true, 'notes' => 'Reviewed against completion criteria'])->assertOk();
        $this->assertSame('completed', $this->action->fresh()->status);
        $this->assertDatabaseCount('skill_evaluations', 0);
        $this->postJson($review, ['accepted' => true, 'notes' => 'Duplicate'])->assertConflict();
    }

    public function test_new_catalog_entries_require_explicit_admin_approval(): void
    {
        $payload = ['title' => 'New SQL tutorial', 'provider' => 'Official', 'url' => 'https://example.org/tutorial',
            'skill_id' => $this->resource->skill_id, 'language' => 'en', 'level' => 1, 'prerequisites' => 'None'];
        $this->postJson('/api/v1/admin/learning-resources', $payload)->assertForbidden();
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/admin/learning-resources', $payload)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('learning_resources', ['id' => $id, 'status' => 'inactive', 'approved_at' => null]);
        $this->postJson('/api/v1/admin/learning-resources/'.$id.'/approve', [])->assertUnprocessable();
        $this->postJson('/api/v1/admin/learning-resources/'.$id.'/approve', ['reviewed_content_and_access' => true])->assertOk();
        $this->postJson('/api/v1/admin/learning-resources/'.$id.'/deactivate')->assertOk();
        $this->assertDatabaseHas('learning_resources', ['id' => $id, 'status' => 'inactive', 'approved_at' => null]);
    }
}
