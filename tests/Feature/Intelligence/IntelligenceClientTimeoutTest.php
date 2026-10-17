<?php

namespace Tests\Feature\Intelligence;

use App\Services\Intelligence\IntelligenceClient;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Regression coverage for IntelligenceClient timeout resolution.
 *
 * The constructor used to default `$timeout` to the literal 10, which made its
 * own `$timeout > 0` branch always win and left
 * config('services.data_science.timeout') permanently unreachable. Every caller
 * that did `new IntelligenceClient` — notably ProjectMatchingService — therefore
 * used 10 s instead of the configured 60 s, so the first call after a Render
 * free-tier cold start (24.7-33.8 s) failed with a false
 * INTELLIGENCE_UNAVAILABLE even though the service was healthy.
 */
class IntelligenceClientTimeoutTest extends TestCase
{
    private function timeoutOf(IntelligenceClient $client): int
    {
        $property = new ReflectionProperty(IntelligenceClient::class, 'timeout');
        $property->setAccessible(true);

        return (int) $property->getValue($client);
    }

    public function test_a_default_client_uses_the_configured_timeout(): void
    {
        config(['services.data_science.timeout' => 60]);

        $this->assertSame(60, $this->timeoutOf(new IntelligenceClient));
    }

    public function test_a_default_client_follows_a_reconfigured_timeout(): void
    {
        // Proves the value is read at construction time, not hardcoded.
        config(['services.data_science.timeout' => 45]);

        $this->assertSame(45, $this->timeoutOf(new IntelligenceClient));
    }

    public function test_container_resolution_uses_the_configured_timeout(): void
    {
        config(['services.data_science.timeout' => 60]);

        $this->assertSame(60, $this->timeoutOf(app(IntelligenceClient::class)));
    }

    public function test_an_explicit_timeout_still_wins(): void
    {
        config(['services.data_science.timeout' => 60]);

        $this->assertSame(7, $this->timeoutOf(new IntelligenceClient(timeout: 7)));
    }

    public function test_a_non_positive_timeout_falls_back_to_the_config(): void
    {
        config(['services.data_science.timeout' => 60]);

        $this->assertSame(60, $this->timeoutOf(new IntelligenceClient(timeout: 0)));
        $this->assertSame(60, $this->timeoutOf(new IntelligenceClient(timeout: -5)));
    }

    /**
     * Drift guard on the shipped fallback. The configured value must survive a
     * cold start, so the literal in config/services.php must not drop back to a
     * value that a free-tier idle wake-up can exceed.
     */
    public function test_the_shipped_config_fallback_is_sixty_seconds(): void
    {
        $source = file_get_contents(base_path('config/services.php'));

        $this->assertStringContainsString(
            "env('DATA_SCIENCE_SERVICE_TIMEOUT', 60)",
            $source,
            'config/services.php must keep a 60 s fallback for the intelligence timeout.',
        );
    }
}
