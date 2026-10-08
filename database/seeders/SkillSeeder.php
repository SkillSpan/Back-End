<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Skill taxonomy used by the dynamic assessment prototype.
 *
 * Seeded idempotently on `slug` (Str::slug(name)), so re-running never
 * creates duplicates and existing skills are simply refreshed. The
 * prototype career roles (CareerRoleSeeder) and baseline questions
 * (BaselineAssessmentItemSeeder) resolve skills by these slugs — no
 * hard-coded ids anywhere.
 */
class SkillSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $skills = [
            // General / uncategorized programming languages
            ['name' => 'Python', 'category' => null],
            ['name' => 'JavaScript', 'category' => null],
            ['name' => 'TypeScript', 'category' => null],
            ['name' => 'Java', 'category' => null],
            ['name' => 'SQL', 'category' => null],
            ['name' => 'C++', 'category' => null],
            ['name' => 'Rust', 'category' => null],

            // Web Development
            ['name' => 'React', 'category' => 'Web Development'],
            ['name' => 'Node.js', 'category' => 'Web Development'],
            ['name' => 'REST APIs', 'category' => 'Web Development'],
            ['name' => 'GraphQL', 'category' => 'Web Development'],
            ['name' => 'CSS / Tailwind', 'category' => 'Web Development'],
            ['name' => 'Next.js', 'category' => 'Web Development'],
            ['name' => 'HTML', 'category' => 'Web Development'],
            ['name' => 'CSS', 'category' => 'Web Development'],
            ['name' => 'Responsive Design', 'category' => 'Web Development'],
            ['name' => 'Frontend Frameworks', 'category' => 'Web Development'],

            // Data & Analytics
            ['name' => 'Pandas', 'category' => 'Data & Analytics'],
            ['name' => 'NumPy', 'category' => 'Data & Analytics'],
            ['name' => 'Statistics Fundamentals', 'category' => 'Data & Analytics'],
            ['name' => 'Statistics', 'category' => 'Data & Analytics'],
            ['name' => 'Data Cleaning', 'category' => 'Data & Analytics'],
            ['name' => 'Data Visualization', 'category' => 'Data & Analytics'],
            ['name' => 'Data Analysis', 'category' => 'Data & Analytics'],
            ['name' => 'Feature Engineering', 'category' => 'Data & Analytics'],

            // Machine Learning & AI
            ['name' => 'Machine Learning', 'category' => 'Machine Learning'],
            ['name' => 'Neural Networks', 'category' => 'Machine Learning'],
            ['name' => 'Model Evaluation', 'category' => 'Machine Learning'],
            ['name' => 'Model Training', 'category' => 'Machine Learning'],
            ['name' => 'Model Deployment', 'category' => 'Machine Learning'],
            ['name' => 'Model Monitoring', 'category' => 'Machine Learning'],
            ['name' => 'AI Fundamentals', 'category' => 'Artificial Intelligence'],
            ['name' => 'Natural Language Processing', 'category' => 'Artificial Intelligence'],
            ['name' => 'Tokenization', 'category' => 'Artificial Intelligence'],
            ['name' => 'Word Embeddings', 'category' => 'Artificial Intelligence'],
            ['name' => 'TF-IDF', 'category' => 'Artificial Intelligence'],
            ['name' => 'Text Classification', 'category' => 'Artificial Intelligence'],

            // Backend & Architecture
            ['name' => 'Programming Fundamentals', 'category' => 'Backend'],
            ['name' => 'SQL & Databases', 'category' => 'Backend'],
            ['name' => 'Authentication & Authorization', 'category' => 'Backend'],
            ['name' => 'Backend Architecture', 'category' => 'Backend'],

            // Mobile
            ['name' => 'Android Development', 'category' => 'Mobile'],
            ['name' => 'Application Lifecycle', 'category' => 'Mobile'],
            ['name' => 'UI Components', 'category' => 'Mobile'],
            ['name' => 'REST API Integration', 'category' => 'Mobile'],
            ['name' => 'Local Data Storage', 'category' => 'Mobile'],

            // IT & Operating Systems
            ['name' => 'Computer Hardware', 'category' => 'IT'],
            ['name' => 'Operating Systems', 'category' => 'IT'],
            ['name' => 'Troubleshooting', 'category' => 'IT'],
            ['name' => 'Servers', 'category' => 'IT'],
            ['name' => 'User & Permission Management', 'category' => 'IT'],
            ['name' => 'Backup & Recovery', 'category' => 'IT'],
            ['name' => 'System Monitoring', 'category' => 'IT'],

            // Networking
            ['name' => 'Networking Fundamentals', 'category' => 'Networking'],
            ['name' => 'Internet Fundamentals', 'category' => 'Networking'],
            ['name' => 'TCP/IP', 'category' => 'Networking'],
            ['name' => 'IP Addressing', 'category' => 'Networking'],
            ['name' => 'Routing & Switching', 'category' => 'Networking'],
            ['name' => 'Network Security', 'category' => 'Networking'],
            ['name' => 'Network Troubleshooting', 'category' => 'Networking'],

            // Business & Systems Analysis
            ['name' => 'Requirements Analysis', 'category' => 'Business Analysis'],
            ['name' => 'Stakeholder Analysis', 'category' => 'Business Analysis'],
            ['name' => 'Business Requirements', 'category' => 'Business Analysis'],
            ['name' => 'Use Cases', 'category' => 'Business Analysis'],
            ['name' => 'Process Analysis', 'category' => 'Business Analysis'],
            ['name' => 'System Analysis', 'category' => 'Systems Analysis'],
            ['name' => 'Requirements Engineering', 'category' => 'Systems Analysis'],
            ['name' => 'UML', 'category' => 'Systems Analysis'],
            ['name' => 'Data Flow', 'category' => 'Systems Analysis'],
            ['name' => 'System Documentation', 'category' => 'Systems Analysis'],

            // Cybersecurity
            ['name' => 'Cybersecurity Fundamentals', 'category' => 'Cybersecurity'],
            ['name' => 'Threats & Vulnerabilities', 'category' => 'Cybersecurity'],
            ['name' => 'Authentication', 'category' => 'Cybersecurity'],
            ['name' => 'Encryption', 'category' => 'Cybersecurity'],
            ['name' => 'SIEM', 'category' => 'Cybersecurity'],
            ['name' => 'Log Analysis', 'category' => 'Cybersecurity'],
            ['name' => 'Security Monitoring', 'category' => 'Cybersecurity'],
            ['name' => 'Incident Response', 'category' => 'Cybersecurity'],
            ['name' => 'Security Events', 'category' => 'Cybersecurity'],
            ['name' => 'Vulnerability Assessment', 'category' => 'Cybersecurity'],
            ['name' => 'Penetration Testing', 'category' => 'Cybersecurity'],
            ['name' => 'Web Security', 'category' => 'Cybersecurity'],
            ['name' => 'SQL Injection', 'category' => 'Cybersecurity'],
            ['name' => 'Threat Modeling', 'category' => 'Cybersecurity'],

            // Embedded Systems & IoT
            ['name' => 'Embedded Systems', 'category' => 'Embedded Systems'],
            ['name' => 'Microcontrollers', 'category' => 'Embedded Systems'],
            ['name' => 'GPIO', 'category' => 'Embedded Systems'],
            ['name' => 'C/C++', 'category' => 'Embedded Systems'],
            ['name' => 'Memory Management', 'category' => 'Embedded Systems'],
            ['name' => 'IoT Fundamentals', 'category' => 'IoT'],
            ['name' => 'Sensors & Actuators', 'category' => 'IoT'],
            ['name' => 'MQTT', 'category' => 'IoT'],
            ['name' => 'Device Communication', 'category' => 'IoT'],
            ['name' => 'IoT Security', 'category' => 'IoT'],
        ];

        foreach ($skills as $skill) {
            Skill::updateOrCreate(
                ['slug' => Str::slug($skill['name'])],
                [
                    'name' => $skill['name'],
                    'category' => $skill['category'],
                    'status' => 'active',
                    'version' => 1,
                ]
            );
        }
    }
}
