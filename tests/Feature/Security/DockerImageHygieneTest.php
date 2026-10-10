<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * STEP 16 — the production image must not ship development artifacts.
 *
 * The image is built with `COPY . .` (see Dockerfile), so `.dockerignore` is
 * the only thing standing between "what is committed" and "what is deployed".
 * It was a short blocklist that missed a second Laravel application, the
 * session-memory directory and the Postman environment files — all of which
 * were being copied into the production image.
 *
 * This is an invariant guard keyed to the repository's known artifacts: it
 * pins both directions (the dev paths must be excluded, the runtime paths must
 * not), so removing a line or adding an over-broad pattern fails loudly.
 */
class DockerImageHygieneTest extends TestCase
{
    public function test_the_dockerignore_file_exists_and_still_excludes_the_environment_file(): void
    {
        $this->assertFileExists($this->path());

        // The one entry that must never be lost: real secrets live in `.env`.
        $this->assertContains('.env', $this->patterns());
    }

    public function test_the_production_image_excludes_development_artifacts(): void
    {
        $patterns = $this->patterns();

        $mustBeExcluded = [
            // A whole separate Laravel app (PHP ^8.3 / Laravel ^13) tracked in
            // this repo. Never served, never tested, never patched.
            'TaskFlow/',
            // Internal session notes / working memory.
            '.workbuddy-ai/',
            // Postman collections / environments: dev credentials
            // (`learner_password`, seeded tokens) as fixtures. `*postman*` and
            // not `postman_*`, so `SkillSpan_API_Collection.postman_collection.json`
            // is covered too.
            '*postman*.json',
            // Handoff / review / incident reports.
            '**/*.md',
            '**/*.pdf',
            '**/*.patch',
            // Standalone HTML handoff / preview documents.
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

        // A blocklist only breaks the build if it grows a pattern that swallows
        // the application itself. None of these may ever be an entry.
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

    /**
     * `.gitignore` had a corrupted tail: a Windows editor appended a UTF-16LE
     * line (and a stray NUL) after the last real entry. Git treats such a line
     * as a pattern that matches nothing, so it was harmless noise — but it is
     * also invisible, and the same corruption on a real entry would silently
     * stop ignoring it.
     */
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

    /**
     * @return list<string>
     */
    private function patterns(string $file = '.dockerignore'): array
    {
        $lines = file(base_path($file), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_values(array_filter(
            array_map('trim', $lines ?: []),
            // Comments are documentation, not patterns.
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));
    }
}
