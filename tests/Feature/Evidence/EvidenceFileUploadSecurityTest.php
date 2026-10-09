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
 * Security coverage for the `evidence_file` upload on POST /api/v1/evidence.
 *
 * The endpoint previously accepted ANY file type (`file` + `max:10000` only),
 * so a learner could push .php/.phtml, executables, shell scripts or SVG into
 * the private evidence directory. These tests pin the allow-list
 * (pdf, jpg, jpeg, png, webp), the size cap and the authorization boundary so
 * a future edit cannot silently re-open the hole.
 *
 * The type check is content-based (`mimes` guesses the type from the file
 * bytes, not the client-supplied name), so a renamed script is covered too.
 */
class EvidenceFileUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        // Evidence files must land on the private `local` disk
        // (storage/app/private), never on the public one.
        Storage::fake('local');
        Storage::fake('public');

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => 'learner@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        return $user;
    }

    private function learnerWithProfile(): User
    {
        $user = $this->learner();
        StudentProfile::forceCreate(['user_id' => $user->id]);

        return $user;
    }

    private function skill(): Skill
    {
        return Skill::create(['name' => 'SQL', 'slug' => 'sql-'.uniqid()]);
    }

    /**
     * Multipart upload with an explicit JSON Accept header, so validation and
     * authorization failures come back as 422/401/403 rather than a redirect.
     */
    private function uploadEvidence(array $payload)
    {
        return $this->post('/api/v1/evidence', $payload, ['Accept' => 'application/json']);
    }

    // -----------------------------------------------------------------
    // 1–2. Allowed types are accepted and stored privately.
    // -----------------------------------------------------------------

    public function test_a_valid_pdf_upload_is_accepted_and_stored_privately(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $file = UploadedFile::fake()->create('certificate.pdf', 500, 'application/pdf');

        $response = $this->post('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_file' => $file,
        ])->assertStatus(201);

        $evidence = SkillEvidence::findOrFail($response->json('data.id'));

        $this->assertNotNull($evidence->reference);
        $this->assertStringStartsWith('evidence/', $evidence->reference);

        // Stored on the private disk…
        Storage::disk('local')->assertExists($evidence->reference);
        // …and never on the public one.
        Storage::disk('public')->assertMissing($evidence->reference);
    }

    public function test_a_valid_png_upload_is_accepted(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $file = UploadedFile::fake()->image('proof.png', 600, 400);

        $response = $this->post('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_file' => $file,
        ])->assertStatus(201);

        $evidence = SkillEvidence::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($evidence->reference);
    }

    public function test_a_valid_jpeg_upload_is_accepted(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $file = UploadedFile::fake()->image('proof.jpg', 600, 400);

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => $file,
        ])->assertStatus(201);
    }

    public function test_a_valid_webp_upload_is_accepted(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        // UploadedFile::fake()->image() writes a real PNG; build a WebP-looking
        // upload explicitly so the MIME guesser sees image/webp.
        $file = UploadedFile::fake()->create('proof.webp', 200, 'image/webp');

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => $file,
        ])->assertStatus(201);
    }

    // -----------------------------------------------------------------
    // 3. Disallowed types are rejected — including renamed scripts.
    // -----------------------------------------------------------------

    public function test_a_php_upload_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $file = UploadedFile::fake()->create('shell.php', 10, 'application/x-php');

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => $file,
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_file');

        $this->assertDatabaseCount('skill_evidences', 0);
    }

    public function test_a_script_disguised_as_a_pdf_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        // Right extension, wrong content: the type check must follow the
        // bytes, not the client-supplied name.
        $file = UploadedFile::fake()->create('certificate.pdf', 10, 'application/x-php');

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => $file,
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_file');
    }

    public function test_an_svg_upload_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        // SVG is an image but can carry inline script — not on the allow-list.
        $file = UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml');

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => $file,
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_file');
    }

    public function test_a_plain_text_upload_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $file = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => $file,
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_file');
    }

    public function test_an_executable_upload_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $file = UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload');

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => $file,
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_file');
    }

    // -----------------------------------------------------------------
    // 4. Oversized uploads are rejected.
    // -----------------------------------------------------------------

    public function test_an_oversized_upload_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        // 10001 KB is one kilobyte over the documented 10000 KB cap.
        $file = UploadedFile::fake()->create('huge.pdf', 10001, 'application/pdf');

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => $file,
        ])->assertStatus(422)->assertJsonValidationErrors('evidence_file');
    }

    public function test_an_upload_at_the_size_cap_is_accepted(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $file = UploadedFile::fake()->create('boundary.pdf', 10000, 'application/pdf');

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => $file,
        ])->assertStatus(201);
    }

    // -----------------------------------------------------------------
    // 5–6. Authorization boundary.
    // -----------------------------------------------------------------

    public function test_an_unauthenticated_upload_is_rejected(): void
    {
        $skill = $this->skill();

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf'),
        ])->assertStatus(401);
    }

    public function test_a_non_learner_cannot_upload_evidence(): void
    {
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);

        $admin = User::forceCreate([
            'name' => 'Platform Admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $admin->roles()->attach(Role::where('slug', 'admin')->first()->id);

        Sanctum::actingAs($admin);
        $skill = $this->skill();

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf'),
        ])->assertStatus(403);
    }

    public function test_an_authorized_learner_can_upload_evidence(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        $this->assertDatabaseCount('skill_evidences', 1);
    }

    public function test_a_learner_without_a_student_profile_cannot_upload(): void
    {
        Sanctum::actingAs($this->learner());
        $skill = $this->skill();

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf'),
        ])->assertStatus(422);

        $this->assertDatabaseCount('skill_evidences', 0);
    }

    // -----------------------------------------------------------------
    // The URL path must keep working unchanged (no regression).
    // -----------------------------------------------------------------

    public function test_the_evidence_url_path_is_unaffected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/certs/php-advanced.pdf',
        ])->assertStatus(201);
    }
}
