<?php

namespace Tests\Feature\Assessment;

use App\Models\BaselineAssessmentItem;
use App\Models\CareerRole;
use App\Models\Role;
use App\Models\Skill;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\BaselineAssessmentItemSeeder;
use Database\Seeders\CareerRoleSeeder;
use Database\Seeders\CareerRoleSpecializationSeeder;
use Database\Seeders\SkillSeeder;
use Database\Seeders\SpecializationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Question-bank coverage for the baseline assessment.
 *
 * A career role can only be assessed when EVERY skill it requires has at
 * least one active question, otherwise BaselineQuestionSelectionService
 * raises INSUFFICIENT_QUESTION_COVERAGE. These tests pin the skills that were
 * identified as thin to a >= 5 question floor, the shape of every multiple
 * choice question, the question-to-skill linkage, and that the previously
 * failing career roles now start an assessment successfully.
 */
class QuestionBankCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The skills that were reported as having too few questions.
     *
     * @var array<int, string>
     */
    private const THIN_SKILLS = [
        'sql-databases',
        'authentication-authorization',
        'cicd',
        'backup-recovery',
        'cc',
        'nextjs',
        'nodejs',
        'routing-switching',
        'sensors-actuators',
        'tcpip',
        'threats-vulnerabilities',
        'user-permission-management',
    ];

    private Role $learnerRole;

    private string $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        Config::set('services.data_science.baseline.version', 'v1.0');
        Config::set('services.baseline_assessment.deterministic_selection', true);

        $this->version = 'v1.0';

        $this->seed([
            SpecializationsSeeder::class,
            SkillSeeder::class,
            CareerRoleSeeder::class,
            CareerRoleSpecializationSeeder::class,
            BaselineAssessmentItemSeeder::class,
        ]);
    }

    public function test_the_identified_skills_have_at_least_five_questions(): void
    {
        foreach (self::THIN_SKILLS as $slug) {
            $skill = Skill::where('slug', $slug)->first();

            $this->assertNotNull($skill, "Skill [{$slug}] is missing from the taxonomy.");

            $count = $this->questionsFor($skill->id)->count();

            $this->assertGreaterThanOrEqual(
                5,
                $count,
                "Skill [{$slug}] has only {$count} question(s); at least 5 are required.",
            );
        }
    }

    public function test_every_question_is_a_valid_multiple_choice(): void
    {
        $questions = BaselineAssessmentItem::where('assessment_version', $this->version)
            ->where('is_active', true)
            ->get();

        $this->assertNotEmpty($questions);

        foreach ($questions as $question) {
            $options = is_array($question->options) ? array_values(array_map('strval', $question->options)) : [];

            $this->assertSame('single_choice', $question->item_type, "{$question->item_id} is not single_choice.");
            $this->assertNotEmpty(trim((string) $question->question_text), "{$question->item_id} has no question text.");
            $this->assertGreaterThanOrEqual(4, count(array_unique($options)), "{$question->item_id} needs 4 distinct options.");
            $this->assertContains(
                (string) $question->correct_answer,
                $options,
                "{$question->item_id} has a correct answer that is not one of its options.",
            );
        }
    }

    public function test_questions_are_linked_to_the_skill_they_are_filed_under(): void
    {
        foreach (self::THIN_SKILLS as $slug) {
            $skill = Skill::where('slug', $slug)->firstOrFail();

            foreach ($this->questionsFor($skill->id) as $question) {
                $this->assertSame(
                    (int) $skill->id,
                    (int) $question->skill_id,
                    "Question {$question->item_id} is not linked to skill [{$slug}].",
                );
            }
        }
    }

    public function test_the_new_questions_cover_the_expected_topics(): void
    {
        // A light content check that the SQL and auth pools actually probe the
        // concepts they are supposed to, not just that they are non-empty.
        $sql = $this->questionTextsFor('sql-databases');

        foreach (['SELECT', 'WHERE', 'JOIN', 'foreign key', 'index'] as $topic) {
            $this->assertTrue(
                $this->textContains($sql, $topic),
                "The SQL & Databases pool has no question about [{$topic}].",
            );
        }

        $auth = $this->questionTextsFor('authentication-authorization');

        foreach (['hash', 'session', 'token', 'role', 'access'] as $topic) {
            $this->assertTrue(
                $this->textContains($auth, $topic),
                "The Authentication & Authorization pool has no question about [{$topic}].",
            );
        }
    }

    public function test_every_approved_career_role_has_question_coverage(): void
    {
        $roles = CareerRole::where('status', 'approved')->with('roleSkills.skill')->get();

        $this->assertNotEmpty($roles);

        foreach ($roles as $role) {
            $this->assertNotEmpty($role->roleSkills, "Career role [{$role->title}] has no required skills.");

            foreach ($role->roleSkills as $roleSkill) {
                $this->assertNotNull($roleSkill->skill, "Career role [{$role->title}] references a missing skill.");

                $this->assertGreaterThan(
                    0,
                    $this->questionsFor($roleSkill->skill_id)->count(),
                    "Career role [{$role->title}] has no question for required skill [{$roleSkill->skill->name}].",
                );
            }
        }
    }

    public function test_the_previously_failing_career_roles_can_start_an_assessment(): void
    {
        $thinSkillIds = Skill::whereIn('slug', self::THIN_SKILLS)->pluck('id')->all();

        // Every role that requires one of the thin skills — these are the ones
        // that used to fail with INSUFFICIENT_QUESTION_COVERAGE.
        $roles = CareerRole::where('status', 'approved')
            ->whereHas('roleSkills', fn ($query) => $query->whereIn('skill_id', $thinSkillIds))
            ->get();

        $this->assertNotEmpty($roles, 'Expected at least one role to depend on the thin skills.');

        foreach ($roles as $role) {
            Sanctum::actingAs($this->learnerWithProfile());

            $response = $this->postJson('/api/v1/baseline-assessments', [
                'career_role_id' => $role->id,
            ]);

            $response->assertStatus(201);

            $this->assertNotEmpty(
                $response->json('data.questions'),
                "Career role [{$role->title}] started an assessment with no questions.",
            );
        }
    }

    public function test_the_free_track_name_and_behaviour_are_unchanged(): void
    {
        $free = Specialization::where('is_free_track', true)->first();

        $this->assertNotNull($free);
        $this->assertSame('Self-Learning / Free Track', $free->name);
        $this->assertSame(SpecializationsSeeder::FREE_TRACK_NAME, $free->name);
        $this->assertTrue($free->isFreeTrack());
        $this->assertSame(0, $free->careerRoles()->count(), 'The free track must not carry pivot rows.');

        Sanctum::actingAs($this->learnerWithProfile());

        $total = $this->getJson('/api/v1/career-roles?specialization_id='.$free->id)->assertOk()->json('data.total');

        $this->assertSame(CareerRole::where('status', 'approved')->count(), $total);
    }

    // ------------------------------------------------ helpers

    private function questionsFor(int $skillId)
    {
        return BaselineAssessmentItem::where('assessment_version', $this->version)
            ->where('is_active', true)
            ->where('skill_id', $skillId)
            ->get();
    }

    /**
     * Question text plus its options, so a concept that appears only as an
     * option (e.g. the keyword SELECT) still counts as covered.
     *
     * @return array<int, string>
     */
    private function questionTextsFor(string $skillSlug): array
    {
        $skill = Skill::where('slug', $skillSlug)->firstOrFail();

        return $this->questionsFor($skill->id)
            ->map(function (BaselineAssessmentItem $question): string {
                $options = is_array($question->options) ? implode(' ', $question->options) : '';

                return trim((string) $question->question_text.' '.$options);
            })
            ->all();
    }

    /**
     * @param  array<int, string>  $texts
     */
    private function textContains(array $texts, string $needle): bool
    {
        foreach ($texts as $text) {
            if (stripos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function learnerWithProfile(): User
    {
        $user = User::forceCreate([
            'name' => 'Coverage Learner',
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->learnerRole->id);

        StudentProfile::forceCreate(['user_id' => $user->id]);

        return $user;
    }
}
