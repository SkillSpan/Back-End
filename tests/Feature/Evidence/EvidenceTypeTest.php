<?php

namespace Tests\Feature\Evidence;

use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage for the four evidence kinds the frontend submits.
 *
 * The endpoint used to accept exactly one shape — "a URL or a file" — and
 * stored every learner submission with `source = certificate`. The frontend now
 * distinguishes a GitHub repository, a live deployment, a design file and an
 * uploaded document, so the backend has to:
 *
 *   - record which kind it was given (`evidence_type`), without touching
 *     `source`, which is the weighted skill-evaluation channel and not a file
 *     label;
 *   - require a URL for the link-based kinds and a file for `document`, and
 *     refuse the unused half instead of silently dropping it;
 *   - keep every legacy request (no `evidence_type` at all) working exactly as
 *     before, because the old clients and the old tests still exist.
 *
 * These tests drive the real HTTP endpoint, because the interesting failures
 * live in the hand-off between validation and persistence — a rule that accepts
 * a request the controller then stores half of is exactly what the previous
 * version of this endpoint did with `description`.
 */
class EvidenceTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        // Evidence files must land on the private `local` disk.
        Storage::fake('local');
        Storage::fake('public');

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', $role)->first()->id);

        return $user;
    }

    private function learnerWithProfile(): User
    {
        $user = $this->user('learner');
        StudentProfile::forceCreate(['user_id' => $user->id]);

        return $user;
    }

    private function skill(): Skill
    {
        return Skill::create(['name' => 'SQL', 'slug' => 'sql-'.uniqid()]);
    }

    private function submit(array $payload)
    {
        return $this->post('/api/v1/evidence', $payload, ['Accept' => 'application/json']);
    }

    // -----------------------------------------------------------------
    // 1. Submission — one test per evidence kind.
    // -----------------------------------------------------------------

    public function test_a_github_repository_can_be_submitted(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $response = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'github_repository',
            'evidence_url' => 'https://github.com/owner/project',
            'description' => 'Built the backend using Laravel and MySQL.',
            'evidence_date' => '2026-11-01',
        ])->assertStatus(201);

        $this->assertSame('github_repository', $response->json('data.evidence_type'));
        $this->assertSame('pending', $response->json('data.verification_status'));

        $this->assertDatabaseHas('skill_evidences', [
            'id' => $response->json('data.id'),
            'skill_id' => $skill->id,
            'evidence_type' => 'github_repository',
            'reference' => 'https://github.com/owner/project',
            'description' => 'Built the backend using Laravel and MySQL.',
            'verification_status' => 'pending',
        ]);
    }

    public function test_a_live_deployment_can_be_submitted(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $response = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'live_deployment',
            'evidence_url' => 'https://example.vercel.app',
            'description' => 'Deployed and maintained the application.',
        ])->assertStatus(201);

        $this->assertSame('live_deployment', $response->json('data.evidence_type'));
    }

    public function test_a_design_file_can_be_submitted(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $response = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'design_file',
            'evidence_url' => 'https://www.figma.com/file/abc123/Checkout-redesign',
        ])->assertStatus(201);

        $this->assertSame('design_file', $response->json('data.evidence_type'));
    }

    public function test_a_document_can_be_uploaded(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $response = $this->submit([
            'skill_id' => $skill->id,
            'evidence_type' => 'document',
            'evidence_file' => UploadedFile::fake()->create('certificate.pdf', 500, 'application/pdf'),
            'description' => 'Course certificate.',
        ])->assertStatus(201);

        $evidence = SkillEvidence::findOrFail($response->json('data.id'));

        $this->assertSame('document', $evidence->evidence_type);
        $this->assertStringStartsWith('evidence/', $evidence->reference);

        // Private disk only — never the web-served one.
        Storage::disk('local')->assertExists($evidence->reference);
        Storage::disk('public')->assertMissing($evidence->reference);
    }

    /**
     * `source` must keep its evaluation meaning. A GitHub repository is still
     * the `certificate` channel — the unassessed, self-submitted one — because
     * re-labelling it `project_performance` would multiply its confidence
     * contribution by seven without anyone deciding that.
     */
    public function test_the_evaluation_source_is_unchanged_for_every_type(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        foreach (SkillEvidence::EVIDENCE_TYPES as $index => $type) {
            $payload = [
                'skill_id' => $skill->id,
                'evidence_type' => $type,
            ];

            if ($type === SkillEvidence::TYPE_GITHUB_REPOSITORY) {
                $payload['evidence_url'] = 'https://github.com/owner/project-'.$index;
            } elseif (in_array($type, SkillEvidence::URL_BASED_TYPES, true)) {
                $payload['evidence_url'] = 'https://example.com/proof-'.$index;
            } else {
                $payload['evidence_file'] = UploadedFile::fake()->create('proof-'.$index.'.pdf', 20, 'application/pdf');
            }

            $response = $this->submit($payload)->assertStatus(201);

            $this->assertSame(
                'certificate',
                SkillEvidence::findOrFail($response->json('data.id'))->source,
                "evidence_type {$type} must not change the evaluation source"
            );
        }
    }

    // -----------------------------------------------------------------
    // 2. Validation — the type decides which half of the payload is legal.
    // -----------------------------------------------------------------

    public function test_an_unknown_evidence_type_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'youtube_video',
            'evidence_url' => 'https://example.com/proof',
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_type');

        $this->assertDatabaseCount('skill_evidences', 0);
    }

    public function test_a_github_repository_without_a_url_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'github_repository',
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_url');
    }

    public function test_a_github_repository_must_be_a_github_url(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'github_repository',
            'evidence_url' => 'https://gitlab.com/owner/project',
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_url');
    }

    public function test_a_github_repository_must_link_to_a_repository_not_a_profile(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'github_repository',
            'evidence_url' => 'https://github.com/owner',
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_url');
    }

    public function test_a_github_repository_must_use_https(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'github_repository',
            'evidence_url' => 'http://github.com/owner/project',
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_url');
    }

    public function test_a_design_file_without_a_url_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'design_file',
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_url');
    }

    public function test_a_document_without_a_file_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'document',
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_file');

        $this->assertDatabaseCount('skill_evidences', 0);
    }

    /**
     * A document is a file. Sending a URL instead used to be accepted and the
     * URL silently stored as if it were a document, which is a 201 for a proof
     * that does not exist.
     */
    public function test_a_document_sent_as_a_url_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'document',
            'evidence_url' => 'https://example.com/certificate.pdf',
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_url');
    }

    /**
     * And the reverse: a repository is a link, so an upload is refused rather
     * than stored and ignored.
     */
    public function test_a_url_based_type_sent_with_a_file_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->submit([
            'skill_id' => $skill->id,
            'evidence_type' => 'github_repository',
            'evidence_file' => UploadedFile::fake()->create('repo.pdf', 20, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_file');

        $this->assertDatabaseCount('skill_evidences', 0);
    }

    public function test_a_malformed_url_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'live_deployment',
            'evidence_url' => 'not a url',
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_url');
    }

    /**
     * Laravel's `url` rule accepts any scheme, so this has to be refused
     * explicitly — a `javascript:` reference echoed into the admin panel is a
     * stored-XSS vector, and `ftp://` is not a proof anyone can open.
     */
    public function test_a_non_web_url_scheme_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        foreach (['javascript:alert(1)', 'ftp://example.com/proof', 'file:///etc/passwd'] as $url) {
            $this->postJson('/api/v1/evidence', [
                'skill_id' => $skill->id,
                'evidence_type' => 'live_deployment',
                'evidence_url' => $url,
            ])->assertStatus(422)->assertJsonValidationErrors('evidence_url');
        }
    }

    public function test_a_private_network_url_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        foreach (['http://localhost:8000', 'http://127.0.0.1/proof', 'http://192.168.1.10/app'] as $url) {
            $this->postJson('/api/v1/evidence', [
                'skill_id' => $skill->id,
                'evidence_type' => 'live_deployment',
                'evidence_url' => $url,
            ])->assertStatus(422)->assertJsonValidationErrors('evidence_url');
        }
    }

    public function test_a_document_with_a_disallowed_file_type_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        foreach ([
            ['shell.php', 'application/x-php'],
            ['payload.svg', 'image/svg+xml'],
            ['notes.txt', 'text/plain'],
            ['payload.exe', 'application/x-msdownload'],
        ] as [$name, $mime]) {
            $this->submit([
                'skill_id' => $skill->id,
                'evidence_type' => 'document',
                'evidence_file' => UploadedFile::fake()->create($name, 10, $mime),
            ])->assertStatus(422)->assertJsonValidationErrors('evidence_file');
        }

        $this->assertDatabaseCount('skill_evidences', 0);
    }

    public function test_an_oversized_document_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->submit([
            'skill_id' => $skill->id,
            'evidence_type' => 'document',
            // One kilobyte over the 10 MB cap the frontend displays.
            'evidence_file' => UploadedFile::fake()->create('huge.pdf', 10001, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_file');
    }

    public function test_a_document_at_the_size_cap_is_accepted(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->submit([
            'skill_id' => $skill->id,
            'evidence_type' => 'document',
            'evidence_file' => UploadedFile::fake()->create('boundary.pdf', 10000, 'application/pdf'),
        ])->assertStatus(201);
    }

    public function test_an_inactive_skill_cannot_receive_evidence(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());

        $skill = Skill::create(['name' => 'Retired', 'slug' => 'retired-'.uniqid()]);
        $skill->forceFill(['status' => 'retired'])->save();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'live_deployment',
            'evidence_url' => 'https://example.com/app',
        ])->assertStatus(422)->assertJsonValidationErrors('skill_id');
    }

    public function test_the_type_is_case_and_whitespace_normalised(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $response = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => '  GitHub_Repository  ',
            'evidence_url' => 'https://github.com/owner/project',
        ])->assertStatus(201);

        $this->assertSame('github_repository', $response->json('data.evidence_type'));
    }

    // -----------------------------------------------------------------
    // 3. Authorization — unchanged boundaries, re-checked for the new paths.
    // -----------------------------------------------------------------

    public function test_an_unauthenticated_submission_is_rejected(): void
    {
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'github_repository',
            'evidence_url' => 'https://github.com/owner/project',
        ])->assertStatus(401);
    }

    public function test_a_non_learner_cannot_submit_evidence(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'github_repository',
            'evidence_url' => 'https://github.com/owner/project',
        ])->assertStatus(403);

        $this->assertDatabaseCount('skill_evidences', 0);
    }

    public function test_a_learner_without_a_student_profile_cannot_submit(): void
    {
        Sanctum::actingAs($this->user('learner'));
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'github_repository',
            'evidence_url' => 'https://github.com/owner/project',
        ])->assertStatus(422);

        $this->assertDatabaseCount('skill_evidences', 0);
    }

    public function test_a_learner_cannot_read_another_learners_typed_evidence(): void
    {
        $owner = $this->learnerWithProfile();
        $other = $this->learnerWithProfile();
        $skill = $this->skill();

        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'github_repository',
            'evidence_url' => 'https://github.com/owner/project',
        ])->assertStatus(201)->json('data.id');

        Sanctum::actingAs($other);

        $this->getJson("/api/v1/evidence/{$id}")->assertStatus(404);
        $this->getJson('/api/v1/evidence')->assertOk()->assertJsonCount(0, 'data');
    }

    // -----------------------------------------------------------------
    // 4. Duplicates — the rule is per (learner, skill, type, reference).
    // -----------------------------------------------------------------

    public function test_the_same_typed_evidence_twice_is_a_duplicate(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $payload = [
            'skill_id' => $skill->id,
            'evidence_type' => 'github_repository',
            'evidence_url' => 'https://github.com/owner/project',
        ];

        $this->postJson('/api/v1/evidence', $payload)->assertStatus(201);
        $this->postJson('/api/v1/evidence', $payload)->assertStatus(409);

        $this->assertDatabaseCount('skill_evidences', 1);
    }

    public function test_a_different_type_for_the_same_skill_is_not_a_duplicate(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'github_repository',
            'evidence_url' => 'https://github.com/owner/project',
        ])->assertStatus(201);

        // A different proof of the same skill is a different claim, so it must
        // not be swallowed by the previous record.
        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'live_deployment',
            'evidence_url' => 'https://project.vercel.app',
        ])->assertStatus(201);

        $this->assertDatabaseCount('skill_evidences', 2);
    }

    public function test_a_legacy_submission_without_a_type_still_detects_duplicates(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $payload = [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/my-certificate',
        ];

        $this->postJson('/api/v1/evidence', $payload)->assertStatus(201);
        $this->postJson('/api/v1/evidence', $payload)->assertStatus(409);
    }

    /**
     * The legacy contract itself: no `evidence_type`, a URL or a file, and the
     * row records "type not recorded" rather than a guessed label.
     */
    public function test_a_legacy_url_submission_stores_a_null_type(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $response = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/my-certificate',
        ])->assertStatus(201);

        $this->assertNull($response->json('data.evidence_type'));
    }

    /**
     * A lone upload with no declared type is unambiguously a document, so it is
     * recorded as one instead of left unlabelled.
     */
    public function test_a_legacy_file_submission_is_recorded_as_a_document(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $response = $this->submit([
            'skill_id' => $skill->id,
            'evidence_file' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        $this->assertSame('document', $response->json('data.evidence_type'));
    }

    // -----------------------------------------------------------------
    // 5. Status lifecycle for typed evidence.
    // -----------------------------------------------------------------

    public function test_typed_evidence_is_listed_in_every_status_and_can_be_filtered(): void
    {
        $learner = $this->learnerWithProfile();
        $skill = $this->skill();

        Sanctum::actingAs($learner);

        $id = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'design_file',
            'evidence_url' => 'https://www.figma.com/file/abc123/Checkout',
        ])->assertStatus(201)->json('data.id');

        // Pending is visible to its owner before anyone reviews it.
        $this->getJson('/api/v1/evidence?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        Sanctum::actingAs($this->user('admin'));

        $this->putJson("/api/v1/evidence/{$id}/review", [
            'verification_status' => 'verified',
            'reviewer_notes' => 'Repository inspected.',
        ])->assertOk();

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/evidence?status=verified')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->getJson('/api/v1/evidence?status=pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // The type survives the review untouched.
        $this->getJson("/api/v1/evidence/{$id}")
            ->assertOk()
            ->assertJsonPath('data.evidence_type', 'design_file')
            ->assertJsonPath('data.verification_status', 'verified');
    }

    public function test_typed_evidence_can_be_sent_back_for_revision_and_resubmitted(): void
    {
        $learner = $this->learnerWithProfile();
        $skill = $this->skill();

        Sanctum::actingAs($learner);

        $id = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_type' => 'live_deployment',
            'evidence_url' => 'https://example.vercel.app',
        ])->assertStatus(201)->json('data.id');

        Sanctum::actingAs($this->user('admin'));

        $this->putJson("/api/v1/evidence/{$id}/review", [
            'verification_status' => 'revision',
            'reviewer_notes' => 'The deployment link returns 404.',
        ])->assertOk()
            ->assertJsonPath('data.verification_status', 'revision');

        // Revision is not terminal — a corrected decision is allowed.
        $this->putJson("/api/v1/evidence/{$id}/review", [
            'verification_status' => 'verified',
        ])->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');

        // Verified is terminal for this endpoint.
        $this->putJson("/api/v1/evidence/{$id}/review", [
            'verification_status' => 'rejected',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_EVIDENCE_TRANSITION');
    }
}
