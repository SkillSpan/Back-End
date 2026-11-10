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

class EvidenceFileUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

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

    private function uploadEvidence(array $payload)
    {
        return $this->post('/api/v1/evidence', $payload, ['Accept' => 'application/json']);
    }

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

        Storage::disk('local')->assertExists($evidence->reference);
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

        $file = UploadedFile::fake()->create('proof.webp', 200, 'image/webp');

        $this->uploadEvidence([
            'skill_id' => $skill->id,
            'evidence_file' => $file,
        ])->assertStatus(201);
    }

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

    public function test_an_oversized_upload_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

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
