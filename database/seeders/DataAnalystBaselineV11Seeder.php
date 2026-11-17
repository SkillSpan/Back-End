<?php

namespace Database\Seeders;

use App\Models\BaselineAssessmentItem;
use App\Models\Skill;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DataAnalystBaselineV11Seeder extends Seeder
{
    public function run(): void
    {
        $bank = json_decode(file_get_contents(database_path('data/data-analyst-v1.1.json')), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($bank): void {
            foreach ($bank['skills'] as $slug => $questions) {
                $skill = Skill::where('slug', $slug)->where('status', 'active')->first();
                if (! $skill) {
                    throw new RuntimeException('Missing active skill: '.$slug);
                }
                foreach ($questions as $index => [$text, $options, $answer, $rationale]) {
                    BaselineAssessmentItem::firstOrCreate([
                        'assessment_version' => $bank['version'],
                        'item_id' => $slug.'-v11-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    ], [
                        'skill_id' => $skill->id, 'item_type' => 'single_choice',
                        'question_text' => $text, 'options' => $options,
                        'correct_answer' => $options[$answer], 'scoring_rule' => null,
                        'weight' => 1, 'is_active' => true,
                    ]);
                }
            }
        });
    }
}
