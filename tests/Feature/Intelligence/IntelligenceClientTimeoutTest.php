<?php

namespace Tests\Feature\Intelligence;

use App\Services\Intelligence\IntelligenceClient;
use ReflectionProperty;
use Tests\TestCase;

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
