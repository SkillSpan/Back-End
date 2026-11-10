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

        $this->adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);

        $this->seed([
            SpecializationsSeeder::class,
            SkillSeeder::class,
            CareerRoleSeeder::class,
            CareerRoleSpecializationSeeder::class,
            BaselineAssessmentItemSeeder::class,
            MvpCatalogSeeder::class,
        ]);
    }

    public function test_data_science_lists_its_six_roles_first(): void
    {
        $dataScience = Specialization::where('name', 'Data Science')->firstOrFail();

        $titles = $this->careerRoleTitles($dataScience);

        $this->assertSame(
            [
                'Analytics Engineer',
                'Business Intelligence Analyst',
                'Data Analyst',
                'Data Engineer',
                'Data Scientist',
                'Machine Learning Engineer',
            ],
            array_slice($titles, 0, 6),
        );
    }

    public function test_every_other_active_role_follows_the_priority_block(): void
    {
        $dataScience = Specialization::where('name', 'Data Science')->firstOrFail();

        $titles = $this->careerRoleTitles($dataScience);

        $this->assertSame(
            CareerRole::where('status', 'approved')->where('is_active', true)->count(),
            count($titles),
        );

        $rest = array_slice($titles, 6);

        foreach (array_slice($titles, 0, 6) as $mapped) {
            $this->assertNotContains($mapped, $rest, "[{$mapped}] appears twice across the two blocks.");
        }

        foreach (['Frontend Developer', 'Backend Developer', 'Full Stack Developer', 'Mobile Developer'] as $title) {
            $this->assertContains($title, $rest, "[{$title}] must still be offered after the priority block.");
        }

        $this->assertSame(count($titles), count(array_unique($titles)));
    }

    public function test_changing_the_specialization_changes_the_priority(): void
    {
        $dataScience = Specialization::where('name', 'Data Science')->firstOrFail();
        $computerScience = Specialization::where('name', 'Computer Science')->firstOrFail();

        $dataScienceTitles = $this->careerRoleTitles($dataScience);
        $computerScienceTitles = $this->careerRoleTitles($computerScience);

        $this->assertNotSame(
            array_slice($dataScienceTitles, 0, 6),
            array_slice($computerScienceTitles, 0, 6),
            'The priority block must depend on the chosen specialization.',
        );

        $this->assertSame('AI Engineer', $computerScienceTitles[0]);

        $dataScienceSorted = $dataScienceTitles;
        $computerScienceSorted = $computerScienceTitles;
        sort($dataScienceSorted);
        sort($computerScienceSorted);

        $this->assertSame($dataScienceSorted, $computerScienceSorted);
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

        $this->assertSame($unranked, $titles, 'The free track must not apply a priority rank.');
        $this->assertCount(
            CareerRole::where('status', 'approved')->where('is_active', true)->count(),
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

        $this->assertNotContains('Draft Data Role', $this->careerRoleTitles($dataScience));
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
        )->assertOk()
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
            $this->getJson('/admin/api/questions/career-roles?specialization_id='.$specialization->id)
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
