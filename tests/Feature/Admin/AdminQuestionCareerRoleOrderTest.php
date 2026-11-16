<?php

namespace Tests\Feature\Admin;

use App\Models\CareerRole;
use App\Models\Role;
use App\Models\Specialization;
use App\Models\User;
use Database\Seeders\BaselineAssessmentItemSeeder;
use Database\Seeders\CareerRoleSeeder;
use Database\Seeders\CareerRoleSpecializationSeeder;
use Database\Seeders\MvpCatalogSeeder;
use Database\Seeders\SkillSeeder;
use Database\Seeders\SpecializationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminQuestionCareerRoleOrderTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create([
            'name' => 'Admin',
            'slug' => 'admin',
            'description' => '',
        ]);

        $this->seed([
            SpecializationsSeeder::class,
            SkillSeeder::class,
            CareerRoleSeeder::class,
            CareerRoleSpecializationSeeder::class,
            BaselineAssessmentItemSeeder::class,
            MvpCatalogSeeder::class,
        ]);
    }

    public function test_data_science_lists_the_four_mvp_roles(): void
    {
        $dataScience = Specialization::where('name', 'Data Science')->firstOrFail();

        $titles = $this->careerRoleTitles($dataScience);

        $this->assertSame(
            [
                'Backend Developer',
                'Frontend Developer',
                'Full Stack Developer',
                'Mobile Developer',
            ],
            $titles,
        );
    }

    public function test_only_the_four_active_mvp_roles_are_offered(): void
    {
        $dataScience = Specialization::where('name', 'Data Science')->firstOrFail();

        $titles = $this->careerRoleTitles($dataScience);

        $expected = [
            'Backend Developer',
            'Frontend Developer',
            'Full Stack Developer',
            'Mobile Developer',
        ];

        $this->assertSame(4, count($titles));

        $this->assertSame(
            count($titles),
            count(array_unique($titles)),
        );

        foreach ($expected as $title) {
            $this->assertContains($title, $titles);
        }
    }

    public function test_active_specializations_offer_the_same_four_mvp_roles(): void
    {
        $dataScience = Specialization::where('name', 'Data Science')->firstOrFail();
        $computerScience = Specialization::where('name', 'Computer Science')->firstOrFail();

        $dataScienceTitles = $this->careerRoleTitles($dataScience);
        $computerScienceTitles = $this->careerRoleTitles($computerScience);

        sort($dataScienceTitles);
        sort($computerScienceTitles);

        $expected = [
            'Backend Developer',
            'Frontend Developer',
            'Full Stack Developer',
            'Mobile Developer',
        ];

        $this->assertSame($expected, $dataScienceTitles);
        $this->assertSame($expected, $computerScienceTitles);
    }

    public function test_the_free_track_gets_a_neutral_order(): void
    {
        $free = Specialization::where('is_free_track', true)->firstOrFail();

        $titles = $this->careerRoleTitles($free);

        $unranked = CareerRole::query()
            ->selectable()
            ->orderBy('career_roles.title')
            ->pluck('title')
            ->all();

        $this->assertSame(
            $unranked,
            $titles,
            'The free track must not apply a priority rank.',
        );

        $this->assertCount(
            CareerRole::query()->selectable()->count(),
            $titles,
        );
    }

    public function test_deactivated_and_unapproved_roles_never_appear(): void
    {
        $dataScience = Specialization::where('name', 'Data Science')->firstOrFail();

        $titles = $this->careerRoleTitles($dataScience);

        $this->assertNotContains('QA Engineer', $titles);
        $this->assertNotContains('UI Designer', $titles);

        $draft = CareerRole::forceCreate([
            'title' => 'Draft Data Role',
            'slug' => 'draft-data-role',
            'version' => 1,
            'status' => 'draft',
            'effective_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $dataScience->careerRoles()->attach($draft->id);

        $this->assertNotContains(
            'Draft Data Role',
            $this->careerRoleTitles($dataScience),
        );
    }

    public function test_the_skills_dropdown_and_the_question_chain_are_unchanged(): void
    {
        $this->actingAs($this->admin());

        $role = CareerRole::where('slug', 'data-scientist')->firstOrFail();

        $skills = $this->getJson('/admin/api/questions/skills?career_role_id='.$role->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(
            $role->roleSkills()->count(),
            $skills,
            'The skills dropdown must still list exactly the role\'s required skills.',
        );

        $response = $this->getJson(
            '/admin/api/questions/career-roles?specialization_id='
            .Specialization::where('name', 'Data Science')->firstOrFail()->id
        )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Career roles retrieved successfully.');

        $this->assertSame(
            ['id', 'title', 'status'],
            array_keys($response->json('data.0')),
        );
    }

    private function careerRoleTitles(Specialization $specialization): array
    {
        $this->actingAs($this->admin());

        return array_column(
            $this->getJson(
                '/admin/api/questions/career-roles?specialization_id='.$specialization->id
            )
                ->assertOk()
                ->json('data'),
            'title',
        );
    }

    private function admin(): User
    {
        $user = User::forceCreate([
            'name' => 'Platform Admin',
            'email' => uniqid().'admin@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->adminRole->id);

        return $user;
    }
}
