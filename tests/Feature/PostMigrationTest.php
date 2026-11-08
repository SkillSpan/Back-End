<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards that catch the mistakes a region/host migration tends to introduce,
 * without touching the real database.
 *
 * Everything here is static or HTTP-level: no RefreshDatabase, no query
 * against the production MySQL. The latency and connectivity measurements
 * live in `php artisan app:deployment-check`, which is read-only and has to
 * run where the app runs.
 */
class PostMigrationTest extends TestCase
{
    public function test_health_endpoint_answers(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_protected_api_route_is_still_guarded(): void
    {
        // 401 means the route exists and the guard is wired. A 404 would mean
        // the route table is broken; a 500 would mean the app cannot boot.
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

        // A literal host in the config is how a migration silently keeps
        // pointing at the old database.
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
        // Config cache is built after the env is present, never at build time.
        $this->assertStringContainsString('config:cache', $dockerfile);
    }

    public function test_the_route_table_loads_completely(): void
    {
        // A partially-broken route table is the usual symptom of a bad cache
        // or a missing binding after a deploy.
        $this->assertGreaterThan(100, count(app('router')->getRoutes()));
    }
}
