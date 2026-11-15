<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class DockerImageHygieneTest extends TestCase
{
    public function test_the_dockerignore_file_exists_and_still_excludes_the_environment_file(): void
    {
        $this->assertFileExists($this->path());

        $this->assertContains('.env', $this->patterns());
    }

    public function test_the_production_image_excludes_development_artifacts(): void
    {
        $patterns = $this->patterns();

        $mustBeExcluded = [
            'TaskFlow/',
            '.workbuddy-ai/',
            '*postman*.json',
            '**/*.md',
            '**/*.pdf',
            '**/*.patch',
            '/*.html',
        ];

        foreach ($mustBeExcluded as $pattern) {
            $this->assertContains(
                $pattern,
                $patterns,
                "`.dockerignore` must exclude `{$pattern}` — it would otherwise be copied into the image by `COPY . .`.",
            );
        }
    }

    public function test_the_production_image_keeps_everything_the_app_needs_to_run(): void
    {
        $patterns = $this->patterns();

        $mustNotBeExcluded = [
            '*',
            '**',
            '/',
            'app/',
            'bootstrap/',
            'config/',
            'database/',
            'public/',
            'resources/',
            'routes/',
            'artisan',
            'composer.json',
            'composer.lock',
        ];

        foreach ($mustNotBeExcluded as $pattern) {
            $this->assertNotContains(
                $pattern,
                $patterns,
                "`.dockerignore` must not exclude `{$pattern}` — the image would not boot.",
            );
        }
    }

    public function test_the_gitignore_is_plain_utf8_and_still_ignores_the_environment_file(): void
    {
        $contents = file_get_contents(base_path('.gitignore'));

        $this->assertStringNotContainsString("\0", $contents, '`.gitignore` must not contain NUL bytes (UTF-16 corruption).');
        $this->assertTrue(mb_check_encoding($contents, 'UTF-8'), '`.gitignore` must be plain UTF-8.');

        foreach (['.env', '/vendor', '/node_modules', '/.workbuddy-ai/', '/.opencode/'] as $entry) {
            $this->assertContains(
                $entry,
                $this->patterns('.gitignore'),
                "`.gitignore` must keep ignoring `{$entry}`.",
            );
        }
    }

    private function path(): string
    {
        return base_path('.dockerignore');
    }

    private function patterns(string $file = '.dockerignore'): array
    {
        $lines = file(base_path($file), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_values(array_filter(
            array_map('trim', $lines ?: []),
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));
    }
}
