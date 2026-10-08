<?php

namespace Database\Seeders;

use App\Models\Specialization;
use Illuminate\Database\Seeder;

/**
 * Specialization reference data.
 *
 * Two groups:
 *  1. The non-technical rows that already existed (kept so existing
 *     student profiles keep resolving — nothing is deleted).
 *  2. The technical taxonomy the assessment flow is built around, each with
 *     a short description for the admin page.
 *
 * Plus the special "Self-Learning / Free Track" specialization, flagged with
 * `is_free_track` so the career-role selection can treat it as "every role is
 * available" without a pivot row per role.
 *
 * Idempotent: rows are matched by their unique `name`. `is_active` and
 * `is_free_track` are never reset on update, so an administrator's
 * deactivation survives a re-seed.
 */
class SpecializationsSeeder extends Seeder
{
    /**
     * The free-track specialization's name.
     *
     * The behaviour is driven by the `is_free_track` flag, never by this
     * string — renaming the row from the admin page keeps the free track
     * working. The English name follows the project's convention (every
     * other specialization is English).
     */
    public const FREE_TRACK_NAME = 'Self-Learning / Free Track';

    public function run(): void
    {
        // Pre-existing non-technical rows. Created if missing, otherwise
        // left exactly as they are.
        foreach ([
            'Electrical Engineering',
            'Mechanical Engineering',
            'Civil Engineering',
            'Business Administration',
            'Accounting',
            'Marketing',
            'Graphic Design',
            'Nursing',
            'Pharmacy',
            'Law',
        ] as $name) {
            Specialization::firstOrCreate(['name' => $name]);
        }

        foreach ($this->technicalSpecializations() as $name => $description) {
            Specialization::updateOrCreate(
                ['name' => $name],
                ['description' => $description],
            );
        }

        Specialization::updateOrCreate(
            ['name' => self::FREE_TRACK_NAME],
            [
                'description' => 'For self-taught learners: choose any career role in SkillSpan, whatever your academic background.',
                'is_free_track' => true,
            ],
        );
    }

    /**
     * @return array<string, string> name => description
     */
    private function technicalSpecializations(): array
    {
        return [
            'Computer Science' => 'Algorithms, data structures, and the theory and practice of computing.',
            'Software Engineering' => 'Designing, building, and maintaining large software systems.',
            'Information Technology' => 'Deploying, supporting, and maintaining IT infrastructure and services.',
            'Information Systems' => 'Bridging business needs with information technology solutions.',
            'Information Technology Management' => 'Leading IT teams, projects, and strategy in an organisation.',
            'Computer Engineering' => 'Hardware, embedded systems, and the software that runs on them.',
            'Web Development' => 'Building websites and web applications for the browser and the server.',
            'Mobile Application Development' => 'Building native and cross-platform applications for mobile devices.',
            'Data Science' => 'Extracting insight and building predictive models from data.',
            'Data Analytics' => 'Analysing data to support business decisions and reporting.',
            'Data Engineering' => 'Building the pipelines and platforms that move and store data.',
            'Artificial Intelligence' => 'Building systems that reason, learn, and act intelligently.',
            'Machine Learning' => 'Training and operating models that learn from data.',
            'Natural Language Processing' => 'Teaching machines to understand and generate human language.',
            'Computer Vision' => 'Teaching machines to interpret images and video.',
            'Generative AI' => 'Building applications on top of large generative models.',
            'Robotics & Intelligent Systems' => 'Programming robots and autonomous systems.',
            'Cybersecurity' => 'Protecting systems, networks, and data from attack.',
            'Offensive Security' => 'Finding and exploiting weaknesses before attackers do.',
            'Digital Forensics' => 'Investigating cyber incidents and recovering digital evidence.',
            'Computer Networks' => 'Designing, operating, and securing computer networks.',
            'Cloud Computing' => 'Designing and operating workloads on cloud platforms.',
            'DevOps & Infrastructure' => 'Automating build, deployment, and reliable operations.',
            'Database Technology' => 'Designing, tuning, and operating database systems.',
            'UI/UX Design' => 'Designing usable and accessible digital product experiences.',
            'Game Development' => 'Building games and interactive real-time experiences.',
            'Software Quality Assurance' => 'Testing and assuring the quality of software.',
            'Blockchain & Web3' => 'Building decentralised applications and smart contracts.',
            'Digital Transformation' => 'Driving technology-enabled change across an organisation.',
        ];
    }
}
