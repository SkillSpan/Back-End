<?php

namespace Database\Seeders;

use App\Models\CareerRole;
use App\Models\Skill;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Prototype career roles and their required skills.
 *
 * Keyed by a stable slug so re-seeding is idempotent and nothing depends
 * on database ids. Skills are resolved by slug (see SkillSeeder) — a role
 * only links to a skill that actually exists, otherwise it is skipped
 * silently rather than failing the whole seed.
 *
 * Specialization links live in CareerRoleSpecializationSeeder, which runs
 * after this one.
 */
class CareerRoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Requires SkillSeeder to have run first (skills must exist to link).
        $roles = [
            'backend-developer' => [
                'title' => 'Backend Developer',
                'skills' => [
                    'programming-fundamentals' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'sql-databases' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'rest-apis' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'authentication-authorization' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'backend-architecture' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
            'data-analyst' => [
                'title' => 'Data Analyst',
                'skills' => [
                    'statistics-fundamentals' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'sql' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'data-cleaning' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'data-visualization' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'data-analysis' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                ],
            ],
            'ai-engineer' => [
                'title' => 'AI Engineer',
                'skills' => [
                    'python' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'machine-learning' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'neural-networks' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'model-evaluation' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'ai-fundamentals' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
            'frontend-developer' => [
                'title' => 'Frontend Developer',
                'skills' => [
                    'html' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'css' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'javascript' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'responsive-design' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'frontend-frameworks' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                ],
            ],
            'mobile-developer' => [
                'title' => 'Mobile Developer',
                'skills' => [
                    'android-development' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'application-lifecycle' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'ui-components' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'rest-api-integration' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'local-data-storage' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
            'it-support-specialist' => [
                'title' => 'IT Support Specialist',
                'skills' => [
                    'computer-hardware' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'operating-systems' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'networking-fundamentals' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'troubleshooting' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'internet-fundamentals' => ['required_level' => 2.0, 'importance_weight' => 0.5, 'is_critical' => false],
                ],
            ],
            'network-administrator' => [
                'title' => 'Network Administrator',
                'skills' => [
                    'tcpip' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'ip-addressing' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'routing-switching' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'network-security' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'network-troubleshooting' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
            'system-administrator' => [
                'title' => 'System Administrator',
                'skills' => [
                    'operating-systems' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'servers' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'user-permission-management' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'backup-recovery' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'system-monitoring' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
            'business-analyst' => [
                'title' => 'Business Analyst',
                'skills' => [
                    'requirements-analysis' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'stakeholder-analysis' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'business-requirements' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'use-cases' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'process-analysis' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
            'systems-analyst' => [
                'title' => 'Systems Analyst',
                'skills' => [
                    'system-analysis' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'requirements-engineering' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'uml' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'data-flow' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'system-documentation' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
            'data-scientist' => [
                'title' => 'Data Scientist',
                'skills' => [
                    'statistics' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'python' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'machine-learning' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'feature-engineering' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'model-evaluation' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                ],
            ],
            'machine-learning-engineer' => [
                'title' => 'Machine Learning Engineer',
                'skills' => [
                    'machine-learning' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'python' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'model-training' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'model-deployment' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'model-monitoring' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
            'nlp-engineer' => [
                'title' => 'NLP Engineer',
                'skills' => [
                    'natural-language-processing' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'tokenization' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'word-embeddings' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'tf-idf' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'text-classification' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
            'security-analyst' => [
                'title' => 'Security Analyst',
                'skills' => [
                    'cybersecurity-fundamentals' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'threats-vulnerabilities' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'authentication' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'encryption' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'network-security' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
            'soc-analyst' => [
                'title' => 'SOC Analyst',
                'skills' => [
                    'siem' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'log-analysis' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'security-monitoring' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'incident-response' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'security-events' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
            'penetration-tester' => [
                'title' => 'Penetration Tester',
                'skills' => [
                    'vulnerability-assessment' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'penetration-testing' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'web-security' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'sql-injection' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'threat-modeling' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
            'embedded-systems-developer' => [
                'title' => 'Embedded Systems Developer',
                'skills' => [
                    'embedded-systems' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'microcontrollers' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'gpio' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'cc' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'memory-management' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
            'iot-developer' => [
                'title' => 'IoT Developer',
                'skills' => [
                    'iot-fundamentals' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'sensors-actuators' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'mqtt' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'device-communication' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'iot-security' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                ],
            ],
        ];

        foreach ($roles as $slug => $roleData) {
            $careerRole = CareerRole::updateOrCreate(
                ['slug' => $slug, 'version' => 1],
                [
                    'title' => $roleData['title'],
                    'status' => 'approved',
                    'effective_date' => now()->toDateString(),
                ]
            );

            $resolvedSkillIds = [];

            foreach ($roleData['skills'] as $skillSlug => $pivot) {
                $skill = Skill::where('slug', $skillSlug)->first();

                if (! $skill) {
                    continue; // Skip silently if SkillSeeder hasn't created it yet.
                }

                $resolvedSkillIds[] = $skill->id;

                $careerRole->roleSkills()->updateOrCreate(
                    ['skill_id' => $skill->id],
                    $pivot
                );
            }

            /*
             * Converge, do not just add. An environment that previously
             * seeded an earlier role definition would otherwise keep the
             * old required skills (e.g. React / Node.js) alongside the new
             * ones. A stale skill that has no mapped question would make
             * BaselineQuestionSelectionService raise
             * INSUFFICIENT_QUESTION_COVERAGE for the whole role, so the
             * role must own exactly the skills this seeder defines.
             *
             * Guarded on a non-empty resolution so a missing SkillSeeder
             * can never wipe a role's skills.
             */
            if ($resolvedSkillIds !== []) {
                $careerRole->roleSkills()
                    ->whereNotIn('skill_id', $resolvedSkillIds)
                    ->delete();
            }
        }
    }
}
