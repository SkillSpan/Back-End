<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Services\Projects\ProjectAvailabilityService;
use Carbon\Carbon;
use Tests\TestCase;

class ProjectAvailabilityServiceTest extends TestCase
{
    private ProjectAvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProjectAvailabilityService();
    }

    private function makeProject(array $attributes = []): Project
    {
        return Project::make(array_merge([
            'status' => 'open',
            'application_deadline' => Carbon::now()->addWeek(),
        ], $attributes));
    }

    public function test_open_project_with_valid_deadline_is_available(): void
    {
        $project = $this->makeProject();

        $result = $this->service->check($project);

        $this->assertTrue($result->available);
        $this->assertEmpty($result->reasons);
    }

    public function test_draft_project_is_unavailable(): void
    {
        $project = $this->makeProject(['status' => 'draft']);

        $result = $this->service->check($project);

        $this->assertFalse($result->available);
        $this->assertNotEmpty($result->reasons);
    }

    public function test_pending_review_project_is_unavailable(): void
    {
        $project = $this->makeProject(['status' => 'pending_review']);

        $result = $this->service->check($project);

        $this->assertFalse($result->available);
        $this->assertNotEmpty($result->reasons);
    }

    public function test_closed_project_is_unavailable(): void
    {
        $project = $this->makeProject(['status' => 'closed']);

        $result = $this->service->check($project);

        $this->assertFalse($result->available);
        $this->assertNotEmpty($result->reasons);
    }

    public function test_in_progress_project_is_unavailable(): void
    {
        $project = $this->makeProject(['status' => 'in_progress']);

        $result = $this->service->check($project);

        $this->assertFalse($result->available);
        $this->assertNotEmpty($result->reasons);
    }

    public function test_completed_project_is_unavailable(): void
    {
        $project = $this->makeProject(['status' => 'completed']);

        $result = $this->service->check($project);

        $this->assertFalse($result->available);
        $this->assertNotEmpty($result->reasons);
    }

    public function test_archived_project_is_unavailable(): void
    {
        $project = $this->makeProject(['status' => 'archived']);

        $result = $this->service->check($project);

        $this->assertFalse($result->available);
        $this->assertNotEmpty($result->reasons);
    }

    public function test_expired_application_deadline_is_unavailable(): void
    {
        $project = $this->makeProject([
            'application_deadline' => Carbon::now()->subDay(),
        ]);

        $result = $this->service->check($project);

        $this->assertFalse($result->available);
        $this->assertNotEmpty($result->reasons);
    }

    public function test_expired_application_deadline_with_open_status_is_unavailable(): void
    {
        $project = $this->makeProject([
            'status' => 'open',
            'application_deadline' => Carbon::now()->subDay(),
        ]);

        $result = $this->service->check($project);

        $this->assertFalse($result->available);
    }

    public function test_null_application_deadline_is_available(): void
    {
        $project = $this->makeProject(['application_deadline' => null]);

        $result = $this->service->check($project);

        $this->assertTrue($result->available);
        $this->assertEmpty($result->reasons);
    }

    public function test_is_available_method_returns_bool(): void
    {
        $available = $this->makeProject();
        $unavailable = $this->makeProject(['status' => 'draft']);

        $this->assertTrue($this->service->isAvailable($available));
        $this->assertFalse($this->service->isAvailable($unavailable));
    }

    public function test_check_returns_stdClass_with_expected_properties(): void
    {
        $project = $this->makeProject();

        $result = $this->service->check($project);

        $this->assertIsObject($result);
        $this->assertTrue(property_exists($result, 'available'));
        $this->assertTrue(property_exists($result, 'reasons'));
        $this->assertIsArray($result->reasons);
    }

    public function test_both_status_and_deadline_failures_are_reported(): void
    {
        $project = $this->makeProject([
            'status' => 'closed',
            'application_deadline' => Carbon::now()->subDay(),
        ]);

        $result = $this->service->check($project);

        $this->assertFalse($result->available);
        $this->assertCount(2, $result->reasons);
    }
}
