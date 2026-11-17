<?php

namespace App\Console\Commands;

use App\Models\BaselineAssessment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ExportBaselineCalibration extends Command
{
    protected $signature = 'baseline:export-calibration
        {--assessments= : Explicit comma-separated completed attempt IDs}
        {--exclude-profile=* : Profile IDs excluded from the study}
        {--output= : New CSV filename, without directories}';

    protected $description = 'Export explicitly selected Data Analyst attempts as pseudonymous calibration CSV';

    public function handle(): int
    {
        try {
            $name = (string) $this->option('output');
            $ids = (string) $this->option('assessments');
            if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\.csv\z/', $name)
                || ! preg_match('/\A[1-9][0-9]*(,[1-9][0-9]*)*\z/', $ids)) {
                throw new RuntimeException('Provide explicit assessment IDs and a simple new .csv filename.');
            }
            $ids = explode(',', $ids);
            if (count($ids) !== count(array_unique($ids))) {
                throw new RuntimeException('Duplicate assessment IDs are not allowed.');
            }
            $disk = Storage::disk('local');
            $path = 'calibration/'.$name;
            if ($disk->exists($path)) {
                throw new RuntimeException('Output already exists; choose a new filename.');
            }
            $secret = (string) config('app.key');
            if ($secret === '') {
                throw new RuntimeException('An application key is required for pseudonymization.');
            }
            $bank = json_decode(file_get_contents(database_path('data/data-analyst-v1.1.json')), true, flags: JSON_THROW_ON_ERROR);
            $expected = [];
            foreach ($bank['skills'] as $slug => $questions) {
                foreach ($questions as $i => [$text, $options, $correct]) {
                    $expected[$slug.'-v11-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT)] = [$text, $options, $options[$correct], $slug];
                }
            }
            $attempts = BaselineAssessment::with(['questionSnapshots', 'careerRole'])->whereIn('id', $ids)->orderBy('id')->get();
            if ($attempts->count() !== count($ids)) {
                throw new RuntimeException('One or more selected attempts do not exist.');
            }
            $seen = [];
            $rows = [['participant_id', 'assessment_version', 'item_id', 'answer']];
            foreach ($attempts as $attempt) {
                if (in_array((string) $attempt->student_profile_id, array_map('strval', $this->option('exclude-profile')), true)) {
                    throw new RuntimeException('Selection contains a profile excluded from the study.');
                }
                if ($attempt->status !== 'completed' || $attempt->assessment_version !== $bank['version']
                    || $attempt->careerRole?->slug !== 'data-analyst') {
                    throw new RuntimeException('Selection must contain completed Data Analyst attempts of the exact bank version.');
                }
                if (isset($seen[$attempt->student_profile_id])) {
                    throw new RuntimeException('Only one attempt per participant is allowed.');
                }
                $seen[$attempt->student_profile_id] = true;
                $first = BaselineAssessment::where('student_profile_id', $attempt->student_profile_id)
                    ->where('career_role_id', $attempt->career_role_id)->where('assessment_version', $bank['version'])
                    ->where('status', 'completed')->orderBy('id')->value('id');
                if ((int) $first !== (int) $attempt->id) {
                    throw new RuntimeException('Use the first completed attempt for each participant.');
                }
                $snapshots = $attempt->questionSnapshots->keyBy('item_id');
                $responses = collect($attempt->responses ?? []);
                $answers = $responses->keyBy('question_id');
                if ($snapshots->count() !== count($expected) || $attempt->questionSnapshots->count() !== count($expected)
                    || $responses->count() !== count($expected) || $answers->count() !== count($expected)) {
                    throw new RuntimeException('Incomplete or duplicate snapshot/response rows.');
                }
                $participant = 'P'.substr(hash_hmac('sha256', 'baseline-calibration|'.$bank['version'].'|'.$attempt->student_profile_id, $secret), 0, 24);
                foreach ($expected as $itemId => [$text, $options, $correct, $slug]) {
                    $snapshot = $snapshots->get($itemId);
                    $answer = $answers->get($itemId)['answer'] ?? null;
                    $actualOptions = $snapshot?->options ?? [];
                    sort($actualOptions);
                    $sortedOptions = $options;
                    sort($sortedOptions);
                    if (! $snapshot || $snapshot->question_text !== $text || $actualOptions !== $sortedOptions
                        || $snapshot->correct_answer !== $correct || $snapshot->skill_slug !== $slug
                        || $snapshot->item_type !== 'single_choice' || (float) $snapshot->weight !== 1.0
                        || ! in_array($answer, $options, true)) {
                        throw new RuntimeException('Snapshot content or answers differ from the calibration bank.');
                    }
                    $rows[] = [$participant, $bank['version'], $itemId, $answer];
                }
            }
            $stream = fopen('php://temp', 'r+');
            foreach ($rows as $row) {
                fputcsv($stream, $row, ',', '"', '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);
            if (! $disk->put($path, $csv, ['visibility' => 'private'])) {
                throw new RuntimeException('Could not write the private export.');
            }
            $this->info('Exported '.$attempts->count().' participants, '.(count($rows) - 1).' responses.');
            $this->line('Private file: '.$disk->path($path));
            $this->warn('Pseudonymous data: restrict access and do not commit it to Git.');

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
