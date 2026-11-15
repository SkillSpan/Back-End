<?php

namespace Tests\Feature\Intelligence;

use App\Events\SkillDataChanged;
use App\Listeners\RecalculateIntelligence;
use App\Models\StudentProfile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

class QueueConfigurationTest extends TestCase
{
    public function test_the_listener_is_queued(): void
    {
        $this->assertTrue(
            is_subclass_of(RecalculateIntelligence::class, ShouldQueue::class),
        );
    }

    public function test_the_listener_defers_through_the_after_commit_interface(): void
    {
        $this->assertTrue(
            is_subclass_of(RecalculateIntelligence::class, ShouldQueueAfterCommit::class),
            'RecalculateIntelligence must implement ShouldQueueAfterCommit; an '
            .'`afterCommit()` method is NOT read by Laravel and silently does nothing.',
        );
    }

    public function test_the_listener_does_not_reintroduce_a_dead_after_commit_method(): void
    {
        $reflection = new ReflectionClass(RecalculateIntelligence::class);

        $this->assertFalse(
            $reflection->hasMethod('afterCommit'),
            'A method named afterCommit() is dead code: Laravel resolves the flag '
            .'with property_exists(), which is false for methods. Use the interface.',
        );

        $this->assertFalse(
            $reflection->hasProperty('afterCommit'),
            'Use the ShouldQueueAfterCommit interface rather than a public property.',
        );
    }

    public function test_the_dispatcher_marks_the_queued_listener_after_commit(): void
    {
        Queue::fake();

        SkillDataChanged::dispatch(new StudentProfile, 'queue-configuration-test');

        Queue::assertPushed(
            CallQueuedListener::class,
            fn ($job): bool => $job->afterCommit === true,
        );
    }

    public function test_the_shipped_queue_fallback_is_sync_because_there_is_no_worker(): void
    {
        $source = file_get_contents(base_path('config/queue.php'));

        $this->assertStringContainsString(
            "env('QUEUE_CONNECTION', 'sync')",
            $source,
            'The queue fallback must stay `sync`: the Docker image runs no queue:work, '
            .'so a `database` fallback would silently drop every queued job.',
        );
    }
}
