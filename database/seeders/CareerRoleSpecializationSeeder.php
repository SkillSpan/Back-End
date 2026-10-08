<?php

namespace Database\Seeders;

use App\Models\CareerRole;
use App\Models\Specialization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Links each technical specialization to its career roles through the
 * `career_role_specialization` pivot.
 *
 * A role shared by several specializations (Backend Developer) is linked to
 * each of them here — it is the SAME career_roles row, never a copy.
 *
 * The "Self-Learning / Free Track" specialization is deliberately NOT listed:
 * its available roles are every role in the system, resolved at query time
 * from its `is_free_track` flag rather than by inserting a pivot row for
 * every role.
 *
 * Idempotent and non-destructive: `syncWithoutDetaching` never creates a
 * duplicate pair and never removes a link an administrator added from the
 * panel.
 */
class CareerRoleSpecializationSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach ($this->map() as $specializationName => $roleSlugs) {
            $specialization = Specialization::where('name', $specializationName)->first();

            if (! $specialization) {
                continue; // Skip if SpecializationsSeeder hasn't created it yet.
            }

            $careerRoleIds = CareerRole::whereIn('slug', $roleSlugs)->pluck('id')->all();

            if ($careerRoleIds === []) {
                continue;
            }

            $specialization->careerRoles()->syncWithoutDetaching($careerRoleIds);
        }
    }

    /**
     * specialization name => [career role slugs]
     *
     * @return array<string, array<int, string>>
     */
    private function map(): array
    {
        return [
            'Computer Science' => [
                'backend-developer', 'frontend-developer', 'full-stack-developer',
                'software-developer', 'mobile-developer', 'data-analyst',
                'ai-engineer', 'machine-learning-engineer', 'software-engineer',
            ],
            'Software Engineering' => [
                'software-engineer', 'backend-developer', 'frontend-developer',
                'full-stack-developer', 'mobile-developer', 'qa-engineer',
                'test-automation-engineer', 'devops-engineer', 'software-architect',
            ],
            'Information Technology' => [
                'it-support-specialist', 'system-administrator', 'network-administrator',
                'it-administrator', 'cloud-support-engineer', 'technical-support-engineer',
                'it-operations-specialist',
            ],
            'Information Systems' => [
                'business-analyst', 'systems-analyst', 'it-business-analyst',
                'systems-administrator', 'erp-specialist', 'crm-specialist',
                'database-administrator', 'data-analyst',
            ],
            'Information Technology Management' => [
                'it-manager', 'it-project-coordinator', 'it-business-analyst',
                'technology-consultant', 'it-operations-manager', 'digital-transformation-specialist',
            ],
            'Computer Engineering' => [
                'embedded-systems-developer', 'embedded-software-engineer', 'firmware-engineer',
                'iot-developer', 'iot-engineer', 'robotics-engineer',
                'hardware-software-integration-engineer', 'backend-developer',
            ],
            'Web Development' => [
                'frontend-developer', 'backend-developer', 'full-stack-developer',
                'wordpress-developer', 'php-developer', 'laravel-developer',
                'node-js-developer', 'react-developer', 'next-js-developer',
                'web-application-developer',
            ],
            'Mobile Application Development' => [
                'android-developer', 'ios-developer', 'flutter-developer',
                'react-native-developer', 'mobile-application-developer', 'cross-platform-mobile-developer',
            ],
            'Data Science' => [
                'data-analyst', 'data-scientist', 'machine-learning-engineer',
                'data-engineer', 'business-intelligence-analyst', 'analytics-engineer',
            ],
            'Data Analytics' => [
                'data-analyst', 'business-intelligence-analyst', 'bi-developer',
                'product-data-analyst', 'marketing-data-analyst', 'data-visualization-specialist',
            ],
            'Data Engineering' => [
                'data-engineer', 'big-data-engineer', 'etl-developer',
                'analytics-engineer', 'data-platform-engineer', 'database-engineer',
            ],
            'Artificial Intelligence' => [
                'ai-engineer', 'machine-learning-engineer', 'deep-learning-engineer',
                'ai-developer', 'computer-vision-engineer', 'nlp-engineer',
                'generative-ai-engineer', 'ai-solutions-engineer',
            ],
            'Machine Learning' => [
                'machine-learning-engineer', 'ml-developer', 'applied-machine-learning-engineer',
                'mlops-engineer', 'deep-learning-engineer',
            ],
            'Natural Language Processing' => [
                'nlp-engineer', 'nlp-developer', 'conversational-ai-engineer',
                'language-ai-engineer', 'ai-research-assistant',
            ],
            'Computer Vision' => [
                'computer-vision-engineer', 'computer-vision-developer',
                'image-processing-engineer', 'ai-vision-engineer',
            ],
            'Generative AI' => [
                'generative-ai-engineer', 'llm-engineer', 'ai-application-developer',
                'prompt-engineer', 'ai-solutions-engineer', 'rag-engineer',
                'ai-automation-engineer',
            ],
            'Robotics & Intelligent Systems' => [
                'robotics-engineer', 'robotics-software-engineer', 'autonomous-systems-engineer',
                'ai-robotics-engineer', 'computer-vision-engineer', 'embedded-ai-engineer',
            ],
            'Cybersecurity' => [
                'security-analyst', 'soc-analyst', 'cybersecurity-engineer',
                'security-engineer', 'incident-response-analyst', 'threat-intelligence-analyst',
                'security-consultant',
            ],
            'Offensive Security' => [
                'penetration-tester', 'ethical-hacker', 'red-team-operator',
                'vulnerability-analyst', 'security-testing-engineer',
            ],
            'Digital Forensics' => [
                'digital-forensics-analyst', 'dfir-analyst', 'cybercrime-analyst',
                'incident-response-specialist',
            ],
            'Computer Networks' => [
                'network-administrator', 'network-engineer', 'network-support-engineer',
                'network-security-engineer', 'noc-engineer',
            ],
            'Cloud Computing' => [
                'cloud-engineer', 'cloud-administrator', 'cloud-architect',
                'cloud-support-engineer', 'cloud-security-engineer',
            ],
            'DevOps & Infrastructure' => [
                'devops-engineer', 'site-reliability-engineer', 'platform-engineer',
                'infrastructure-engineer', 'devsecops-engineer', 'cicd-engineer',
            ],
            'Database Technology' => [
                'database-administrator', 'database-engineer', 'sql-developer',
                'database-developer', 'data-platform-engineer',
            ],
            'UI/UX Design' => [
                'ui-designer', 'ux-designer', 'product-designer',
                'ux-researcher', 'interaction-designer', 'design-system-designer',
            ],
            'Game Development' => [
                'game-developer', 'unity-developer', 'unreal-engine-developer',
                'gameplay-programmer', 'game-programmer', 'game-designer',
                'technical-game-designer',
            ],
            'Software Quality Assurance' => [
                'qa-engineer', 'software-tester', 'manual-qa-tester',
                'automation-qa-engineer', 'test-automation-engineer', 'performance-test-engineer',
            ],
            'Blockchain & Web3' => [
                'blockchain-developer', 'smart-contract-developer', 'web3-developer',
                'blockchain-engineer', 'solidity-developer', 'web3-backend-developer',
            ],
            'Digital Transformation' => [
                'digital-transformation-specialist', 'technology-consultant',
                'business-systems-analyst', 'automation-specialist', 'digital-solutions-specialist',
            ],
        ];
    }
}
