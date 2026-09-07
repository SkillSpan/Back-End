<?php

namespace Database\Seeders;

use App\Models\BaselineAssessmentItem;
use App\Models\Skill;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BaselineAssessmentItemSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $items = [
            // single_choice: binary correct/incorrect scoring
            [
                'item_id' => 'sql-001',
                'item_type' => 'single_choice',
                'skill' => 'sql',
                'options' => ['A', 'B', 'C', 'D'],
                'correct_answer' => 'B',
                'scoring_rule' => null,
                'weight' => 1.000,
            ],
            [
                'item_id' => 'sql-002',
                'item_type' => 'single_choice',
                'skill' => 'sql',
                'options' => ['A', 'B', 'C', 'D'],
                'correct_answer' => 'A',
                'scoring_rule' => null,
                'weight' => 0.800,
            ],
            [
                'item_id' => 'python-001',
                'item_type' => 'single_choice',
                'skill' => 'python',
                'options' => ['A', 'B', 'C', 'D'],
                'correct_answer' => 'C',
                'scoring_rule' => null,
                'weight' => 1.000,
            ],

            // scale: self-rated confidence, not correct/incorrect — each
            // selected option maps to a partial-credit contribution.
            [
                'item_id' => 'python-002',
                'item_type' => 'scale',
                'skill' => 'python',
                'options' => ['1', '2', '3', '4', '5'],
                'correct_answer' => null,
                'scoring_rule' => ['1' => 0.0, '2' => 0.25, '3' => 0.5, '4' => 0.75, '5' => 1.0],
                'weight' => 0.500,
            ],
            [
                'item_id' => 'javascript-001',
                'item_type' => 'single_choice',
                'skill' => 'javascript',
                'options' => ['A', 'B', 'C', 'D'],
                'correct_answer' => 'D',
                'scoring_rule' => null,
                'weight' => 1.000,
            ],
        ];

        foreach ($items as $item) {
            $skill = Skill::where('slug', $item['skill'])->first();

            if (! $skill) {
                continue; // Skip silently if SkillSeeder hasn't created it yet.
            }

            BaselineAssessmentItem::updateOrCreate(
                ['assessment_version' => 'v1.0', 'item_id' => $item['item_id']],
                [
                    'item_type' => $item['item_type'],
                    'skill_id' => $skill->id,
                    'options' => $item['options'],
                    'correct_answer' => $item['correct_answer'],
                    'scoring_rule' => $item['scoring_rule'],
                    'weight' => $item['weight'],
                    'is_active' => true,
                ]
            );
        }
    }
}
