<?php

namespace Tests\Feature;

use Tests\TestCase;

class PostMigrationTest extends TestCase
{
    public function test_health_endpoint_answers(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_protected_api_route_is_still_guarded(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
    }

    public function test_database_config_reads_every_credential_from_the_environment(): void
    {
        $source = (string) file_get_contents(config_path('database.php'));

        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $this->assertStringContainsString(
                "env('{$key}'",
                $source,
                "config/database.php must read {$key} from the environment.",
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            "/'host'\s*=>\s*'(?!127\.0\.0\.1')[^']*\.(com|net|io|cloud)'/",
            $source,
        );
    }

    public function test_env_example_carries_no_real_credentials(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^DB_HOST=127\.0\.0\.1$/m', $example);
        $this->assertMatchesRegularExpression('/^DB_PASSWORD=$/m', $example);
        $this->assertDoesNotMatchRegularExpression('/^DB_PASSWORD=.+$/m', $example);
    }

    public function test_dockerfile_migrates_and_serves_on_the_injected_port(): void
    {
        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

        $this->assertStringContainsString('migrate --force', $dockerfile);
        $this->assertStringContainsString('serve --host=0.0.0.0', $dockerfile);
        $this->assertStringContainsString('${PORT', $dockerfile);
        $this->assertStringContainsString('config:cache', $dockerfile);
    }

    public function test_the_route_table_loads_completely(): void
    {
        $this->assertGreaterThan(100, count(app('router')->getRoutes()));
    }
}
