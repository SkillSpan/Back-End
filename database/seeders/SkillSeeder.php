<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Skill taxonomy used by the dynamic assessment prototype.
 *
 * Seeded idempotently on `slug`, so re-running never creates duplicates and
 * existing skills are simply refreshed. Career roles (CareerRoleSeeder) and
 * baseline questions (BaselineAssessmentItemSeeder) resolve skills by these
 * slugs — no hard-coded ids anywhere.
 *
 * Entry format: [name, category, slug?]. The slug defaults to
 * Str::slug(name); it is overridden only where that would collide, e.g.
 * "C#" and "C++" both slug to "c".
 */
class SkillSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach ($this->skills() as $skill) {
            [$name, $category] = $skill;
            $slug = $skill[2] ?? Str::slug($name);

            Skill::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'category' => $category,
                    'status' => 'active',
                    'version' => 1,
                ]
            );
        }
    }

    /**
     * @return array<int, array{0: string, 1: ?string, 2?: string}>
     */
    private function skills(): array
    {
        return [
            // ---- Languages / general ----
            ['Python', null],
            ['JavaScript', null],
            ['TypeScript', null],
            ['Java', null],
            ['SQL', null],
            ['C++', null],
            ['Rust', null],
            ['PHP', 'Web Development'],
            ['C#', 'Game Development', 'csharp'],

            // ---- Programming fundamentals ----
            ['Programming Fundamentals', 'Backend'],
            ['Data Structures', 'Programming'],
            ['Algorithms', 'Programming'],
            ['Object-Oriented Programming', 'Programming'],
            ['Software Design', 'Programming'],
            ['Version Control (Git)', 'Programming'],
            ['Debugging', 'Programming'],

            // ---- Web Development ----
            ['React', 'Web Development'],
            ['Node.js', 'Web Development'],
            ['REST APIs', 'Web Development'],
            ['GraphQL', 'Web Development'],
            ['CSS / Tailwind', 'Web Development'],
            ['Next.js', 'Web Development'],
            ['HTML', 'Web Development'],
            ['CSS', 'Web Development'],
            ['Responsive Design', 'Web Development'],
            ['Frontend Frameworks', 'Web Development'],
            ['Laravel', 'Web Development'],
            ['WordPress', 'Web Development'],
            ['Web Application Development', 'Web Development'],
            ['Backend Architecture', 'Backend'],
            ['Authentication & Authorization', 'Backend'],

            // ---- Mobile ----
            ['Android Development', 'Mobile'],
            ['iOS Development', 'Mobile'],
            ['Swift', 'Mobile'],
            ['Kotlin', 'Mobile'],
            ['Flutter', 'Mobile'],
            ['Dart', 'Mobile'],
            ['React Native', 'Mobile'],
            ['Cross-Platform Development', 'Mobile'],
            ['Application Lifecycle', 'Mobile'],
            ['UI Components', 'Mobile'],
            ['REST API Integration', 'Mobile'],
            ['Local Data Storage', 'Mobile'],

            // ---- Data & Analytics ----
            ['Pandas', 'Data & Analytics'],
            ['NumPy', 'Data & Analytics'],
            ['Statistics Fundamentals', 'Data & Analytics'],
            ['Statistics', 'Data & Analytics'],
            ['Data Cleaning', 'Data & Analytics'],
            ['Data Visualization', 'Data & Analytics'],
            ['Data Analysis', 'Data & Analytics'],
            ['Feature Engineering', 'Data & Analytics'],
            ['Data Warehousing', 'Data & Analytics'],
            ['ETL', 'Data & Analytics'],
            ['Apache Spark', 'Data & Analytics'],
            ['Big Data', 'Data & Analytics'],
            ['Data Modeling', 'Data & Analytics'],
            ['Data Pipelines', 'Data & Analytics'],
            ['Power BI', 'Data & Analytics'],
            ['Tableau', 'Data & Analytics'],
            ['Business Intelligence', 'Data & Analytics'],
            ['KPIs and Metrics', 'Data & Analytics'],

            // ---- Machine Learning & AI ----
            ['Machine Learning', 'Machine Learning'],
            ['Neural Networks', 'Machine Learning'],
            ['Model Evaluation', 'Machine Learning'],
            ['Model Training', 'Machine Learning'],
            ['Model Deployment', 'Machine Learning'],
            ['Model Monitoring', 'Machine Learning'],
            ['Deep Learning', 'Machine Learning'],
            ['TensorFlow', 'Machine Learning'],
            ['PyTorch', 'Machine Learning'],
            ['MLOps', 'Machine Learning'],
            ['Model Serving', 'Machine Learning'],
            ['Reinforcement Learning', 'Machine Learning'],
            ['AI Fundamentals', 'Artificial Intelligence'],
            ['Natural Language Processing', 'Artificial Intelligence'],
            ['Tokenization', 'Artificial Intelligence'],
            ['Word Embeddings', 'Artificial Intelligence'],
            ['TF-IDF', 'Artificial Intelligence'],
            ['Text Classification', 'Artificial Intelligence'],
            ['Computer Vision', 'Artificial Intelligence'],
            ['OpenCV', 'Artificial Intelligence'],
            ['Image Processing', 'Artificial Intelligence'],
            ['Generative AI', 'Artificial Intelligence'],
            ['Large Language Models', 'Artificial Intelligence'],
            ['Prompt Engineering', 'Artificial Intelligence'],
            ['Retrieval-Augmented Generation', 'Artificial Intelligence'],

            // ---- IT & Operating Systems ----
            ['Computer Hardware', 'IT'],
            ['Operating Systems', 'IT'],
            ['Troubleshooting', 'IT'],
            ['Servers', 'IT'],
            ['User & Permission Management', 'IT'],
            ['Backup & Recovery', 'IT'],
            ['System Monitoring', 'IT'],
            ['Windows Server', 'IT'],
            ['Active Directory', 'IT'],
            ['Help Desk Operations', 'IT'],
            ['IT Service Management', 'IT'],
            ['Virtualization', 'IT'],

            // ---- Networking ----
            ['Networking Fundamentals', 'Networking'],
            ['Internet Fundamentals', 'Networking'],
            ['TCP/IP', 'Networking'],
            ['IP Addressing', 'Networking'],
            ['Routing & Switching', 'Networking'],
            ['Network Security', 'Networking'],
            ['Network Troubleshooting', 'Networking'],
            ['VLANs', 'Networking'],
            ['OSPF', 'Networking'],
            ['BGP', 'Networking'],
            ['Network Design', 'Networking'],
            ['Wireless Networking', 'Networking'],

            // ---- Cloud & DevOps ----
            ['Cloud Computing Fundamentals', 'Cloud'],
            ['AWS', 'Cloud'],
            ['Microsoft Azure', 'Cloud'],
            ['Docker', 'DevOps'],
            ['Kubernetes', 'DevOps'],
            ['Linux Administration', 'DevOps'],
            ['CI/CD', 'DevOps'],
            ['Infrastructure as Code', 'DevOps'],
            ['Terraform', 'DevOps'],
            ['Observability', 'DevOps'],

            // ---- Databases ----
            ['SQL & Databases', 'Backend'],
            ['Database Design', 'Databases'],
            ['Query Optimization', 'Databases'],
            ['NoSQL', 'Databases'],
            ['Database Indexing', 'Databases'],

            // ---- Business & Systems Analysis ----
            ['Requirements Analysis', 'Business Analysis'],
            ['Stakeholder Analysis', 'Business Analysis'],
            ['Business Requirements', 'Business Analysis'],
            ['Use Cases', 'Business Analysis'],
            ['Process Analysis', 'Business Analysis'],
            ['System Analysis', 'Systems Analysis'],
            ['Requirements Engineering', 'Systems Analysis'],
            ['UML', 'Systems Analysis'],
            ['Data Flow', 'Systems Analysis'],
            ['System Documentation', 'Systems Analysis'],
            ['IT Project Management', 'Business Analysis'],
            ['IT Governance', 'Business Analysis'],
            ['ERP Systems', 'Business Analysis'],
            ['CRM Systems', 'Business Analysis'],
            ['Digital Transformation', 'Business Analysis'],
            ['Process Automation', 'Business Analysis'],
            ['Change Management', 'Business Analysis'],
            ['Technology Consulting', 'Business Analysis'],

            // ---- Cybersecurity ----
            ['Cybersecurity Fundamentals', 'Cybersecurity'],
            ['Threats & Vulnerabilities', 'Cybersecurity'],
            ['Authentication', 'Cybersecurity'],
            ['Encryption', 'Cybersecurity'],
            ['SIEM', 'Cybersecurity'],
            ['Log Analysis', 'Cybersecurity'],
            ['Security Monitoring', 'Cybersecurity'],
            ['Incident Response', 'Cybersecurity'],
            ['Security Events', 'Cybersecurity'],
            ['Vulnerability Assessment', 'Cybersecurity'],
            ['Penetration Testing', 'Cybersecurity'],
            ['Web Security', 'Cybersecurity'],
            ['SQL Injection', 'Cybersecurity'],
            ['Threat Modeling', 'Cybersecurity'],
            ['Security Architecture', 'Cybersecurity'],
            ['Secure Coding', 'Cybersecurity'],
            ['Identity and Access Management', 'Cybersecurity'],
            ['Ethical Hacking', 'Cybersecurity'],
            ['Red Teaming', 'Cybersecurity'],
            ['Exploit Development', 'Cybersecurity'],
            ['Digital Forensics', 'Cybersecurity'],
            ['Incident Handling', 'Cybersecurity'],
            ['Malware Analysis', 'Cybersecurity'],
            ['Evidence Collection', 'Cybersecurity'],
            ['Cryptography', 'Cybersecurity'],

            // ---- UI/UX ----
            ['UI Design', 'UI/UX'],
            ['UX Research', 'UI/UX'],
            ['Wireframing', 'UI/UX'],
            ['Prototyping', 'UI/UX'],
            ['Figma', 'UI/UX'],
            ['Design Systems', 'UI/UX'],
            ['Interaction Design', 'UI/UX'],
            ['Accessibility', 'UI/UX'],

            // ---- Game Development ----
            ['Unity', 'Game Development'],
            ['Unreal Engine', 'Game Development'],
            ['Game Design', 'Game Development'],
            ['Gameplay Programming', 'Game Development'],
            ['3D Mathematics', 'Game Development'],

            // ---- Quality Assurance ----
            ['Manual Testing', 'Quality Assurance'],
            ['Test Case Design', 'Quality Assurance'],
            ['Bug Reporting', 'Quality Assurance'],
            ['Test Automation', 'Quality Assurance'],
            ['Selenium', 'Quality Assurance'],
            ['Performance Testing', 'Quality Assurance'],
            ['API Testing', 'Quality Assurance'],

            // ---- Blockchain ----
            ['Blockchain Fundamentals', 'Blockchain'],
            ['Solidity', 'Blockchain'],
            ['Smart Contracts', 'Blockchain'],
            ['Ethereum', 'Blockchain'],
            ['Web3 Development', 'Blockchain'],

            // ---- Embedded Systems & IoT ----
            ['Embedded Systems', 'Embedded Systems'],
            ['Microcontrollers', 'Embedded Systems'],
            ['GPIO', 'Embedded Systems'],
            ['C/C++', 'Embedded Systems'],
            ['Memory Management', 'Embedded Systems'],
            ['Embedded C', 'Embedded Systems'],
            ['RTOS', 'Embedded Systems'],
            ['Firmware Development', 'Embedded Systems'],
            ['Hardware-Software Integration', 'Embedded Systems'],
            ['Signal Processing', 'Embedded Systems'],
            ['PCB Design', 'Embedded Systems'],
            ['IoT Fundamentals', 'IoT'],
            ['Sensors & Actuators', 'IoT'],
            ['MQTT', 'IoT'],
            ['Device Communication', 'IoT'],
            ['IoT Security', 'IoT'],

            // ---- Robotics ----
            ['Robotics', 'Robotics'],
            ['ROS', 'Robotics'],
            ['Control Systems', 'Robotics'],
        ];
    }
}
