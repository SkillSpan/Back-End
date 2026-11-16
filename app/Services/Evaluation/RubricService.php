<?php

namespace App\Services\Evaluation;

use App\Exceptions\EvaluationException;
use App\Models\Rubric;
use App\Models\RubricDimension;
use App\Models\Skill;
use App\Models\User;
use App\Traits\AuditsActions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RubricService
{
    use AuditsActions;

    public function listFamilies(): array
    {
        $families = Rubric::query()
            ->whereNull('family_id')
            ->with(['versions' => fn ($q) => $q->withCount('dimensions')->orderByDesc('version')])
            ->orderBy('title')
            ->get();

        return $families->map(function (Rubric $family): array {
            $versions = collect([$family])->merge($family->versions)->sortByDesc('version')->values();

            return [
                'id' => $family->id,
                'title' => $family->title,
                'description' => $family->description,
                'applicable_project_types' => $family->applicable_project_types ?? [],
                'score_min' => $family->score_min,
                'score_max' => $family->score_max,
                'latest_version' => $versions->max('version'),
                'selectable_version_id' => $versions
                    ->where('status', Rubric::STATUS_PUBLISHED)
                    ->sortByDesc('version')
                    ->first()?->id,
                'versions' => $versions->sortByDesc('version')->values()->map(
                    fn (Rubric $version) => $this->versionPayload($version)
                )->all(),
            ];
        })->values()->all();
    }

    public function selectable(): Collection
    {
        return Rubric::query()
            ->where('status', Rubric::STATUS_PUBLISHED)
            ->with('dimensions')
            ->orderBy('title')
            ->orderByDesc('version')
            ->get();
    }

    public function create(
        User $actor,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Rubric {
        $this->assertExpert($actor);
        $dimensions = $this->normalizeDimensions($data['dimensions'] ?? []);
        $this->assertWeightsNotAllZero($dimensions);

        $rubric = DB::transaction(function () use ($actor, $data, $dimensions): Rubric {
            $rubric = Rubric::create([
                'title' => (string) $data['title'],
                'description' => $data['description'] ?? null,
                'applicable_project_types' => $data['applicable_project_types'] ?? null,
                'score_min' => (float) ($data['score_min'] ?? 0),
                'score_max' => (float) ($data['score_max'] ?? 100),
                'version' => 1,
                'status' => Rubric::STATUS_DRAFT,
                'created_by' => $actor->id,
            ]);

            $this->replaceDimensions($rubric, $dimensions);

            return $rubric;
        });

        $this->audit(
            $actor->id,
            'rubric.created',
            'rubric',
            $rubric->id,
            null,
            ['title' => $rubric->title, 'version' => $rubric->version, 'dimensions' => count($dimensions)],
            'rubric_governance',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $rubric->fresh(['dimensions.skills']);
    }

    public function updateDraft(
        User $actor,
        Rubric $version,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Rubric {
        $this->assertExpert($actor);
        $this->assertDraft($version);

        $before = $this->versionPayload($version->loadCount('dimensions'));

        DB::transaction(function () use ($version, $data): void {
            $attributes = [];

            foreach (['title', 'description', 'applicable_project_types', 'score_min', 'score_max'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field];
                }
            }

            if ($attributes !== []) {
                $version->forceFill($attributes)->save();
            }

            if (array_key_exists('dimensions', $data)) {
                $dimensions = $this->normalizeDimensions($data['dimensions']);
                $this->assertWeightsNotAllZero($dimensions);
                $this->replaceDimensions($version, $dimensions);
            }
        });

        $this->audit(
            $actor->id,
            'rubric.updated',
            'rubric',
            $version->id,
            $before,
            $this->versionPayload($version->fresh(['dimensions.skills'])),
            'rubric_governance',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $version->fresh(['dimensions.skills']);
    }

    public function createVersion(
        User $actor,
        Rubric $family,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Rubric {
        $this->assertExpert($actor);

        $root = $family->family_id === null ? $family : Rubric::findOrFail($family->family_id);

        $source = Rubric::query()
            ->where(function ($q) use ($root) {
                $q->whereKey($root->id)->orWhere('family_id', $root->id);
            })
            ->orderByDesc('version')
            ->firstOrFail();

        $nextVersion = (int) Rubric::query()
            ->where(function ($q) use ($root) {
                $q->whereKey($root->id)->orWhere('family_id', $root->id);
            })
            ->max('version') + 1;

        $version = DB::transaction(function () use ($actor, $root, $source, $nextVersion): Rubric {
            $version = Rubric::create([
                'family_id' => $root->id,
                'title' => $root->title,
                'description' => $source->description,
                'applicable_project_types' => $source->applicable_project_types,
                'score_min' => $source->score_min,
                'score_max' => $source->score_max,
                'version' => $nextVersion,
                'status' => Rubric::STATUS_DRAFT,
                'created_by' => $actor->id,
            ]);

            $dimensions = $source->dimensions->map(fn (RubricDimension $dimension) => [
                'name' => $dimension->name,
                'weight' => (float) $dimension->weight,
                'score_min' => (float) $dimension->score_min,
                'score_max' => (float) $dimension->score_max,
                'indicators' => $dimension->indicators ?? [],
                'order_index' => (int) $dimension->order_index,
                'skill_ids' => $dimension->skillIds(),
            ])->all();

            $this->replaceDimensions($version, $dimensions);

            return $version;
        });

        $this->audit(
            $actor->id,
            'rubric.version_created',
            'rubric',
            $version->id,
            ['source_version_id' => $source->id, 'source_version' => $source->version],
            ['version' => $version->version],
            'rubric_governance',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $version->fresh(['dimensions.skills']);
    }

    public function publish(
        User $actor,
        Rubric $version,
        string $reason,
        ?string $effectiveDate = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Rubric {
        $this->assertExpert($actor);

        if ($version->status !== Rubric::STATUS_DRAFT) {
            throw new EvaluationException(
                'Only a draft rubric version can be published.',
                422,
                'RUBRIC_VERSION_IMMUTABLE',
                ['rubric_version_id' => $version->id, 'status' => $version->status],
            );
        }

        $version->load('dimensions.skills');
        $this->assertPublishable($version);

        $version->forceFill([
            'status' => Rubric::STATUS_PUBLISHED,
            'published_at' => now(),
            'approved_by' => $actor->id,
            'approval_reason' => $reason,
            'effective_date' => $effectiveDate === null ? now()->toDateString() : $effectiveDate,
        ])->save();

        $this->audit(
            $actor->id,
            'rubric.published',
            'rubric',
            $version->id,
            ['status' => Rubric::STATUS_DRAFT],
            [
                'status' => Rubric::STATUS_PUBLISHED,
                'reason' => $reason,
                'effective_date' => $version->effective_date?->toDateString(),
                'version' => $version->version,
            ],
            'rubric_governance',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $version->fresh(['dimensions.skills']);
    }

    public function deactivate(
        User $actor,
        Rubric $version,
        string $reason,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Rubric {
        $this->assertExpert($actor);

        if ($version->status === Rubric::STATUS_DEACTIVATED) {
            return $version->fresh(['dimensions.skills']);
        }

        if ($version->status !== Rubric::STATUS_PUBLISHED) {
            throw new EvaluationException(
                'Only a published rubric version can be deactivated.',
                422,
                'RUBRIC_VERSION_NOT_PUBLISHED',
                ['rubric_version_id' => $version->id, 'status' => $version->status],
            );
        }

        $version->forceFill([
            'status' => Rubric::STATUS_DEACTIVATED,
            'deactivated_at' => now(),
            'deactivation_reason' => $reason,
        ])->save();

        $this->audit(
            $actor->id,
            'rubric.deactivated',
            'rubric',
            $version->id,
            ['status' => Rubric::STATUS_PUBLISHED],
            ['status' => Rubric::STATUS_DEACTIVATED, 'reason' => $reason],
            'rubric_governance',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $version->fresh(['dimensions.skills']);
    }

    public function payload(Rubric $version): array
    {
        return array_merge($this->versionPayload($version), [
            'dimensions' => $version->dimensions->map(fn (RubricDimension $dimension) => [
                'id' => $dimension->id,
                'name' => $dimension->name,
                'weight' => (float) $dimension->weight,
                'score_min' => (float) $dimension->score_min,
                'score_max' => (float) $dimension->score_max,
                'indicators' => $dimension->indicators ?? [],
                'order_index' => (int) $dimension->order_index,
                'skills' => $dimension->skills->map(fn (Skill $skill) => [
                    'id' => $skill->id,
                    'name' => $skill->name,
                ])->all(),
            ])->all(),
        ]);
    }

    public function versionPayload(Rubric $version): array
    {
        return [
            'id' => $version->id,
            'family_id' => $version->family_id,
            'title' => $version->title,
            'description' => $version->description,
            'version' => (int) $version->version,
            'status' => $version->status,
            'score_min' => (float) $version->score_min,
            'score_max' => (float) $version->score_max,
            'applicable_project_types' => $version->applicable_project_types ?? [],
            'dimensions_count' => $version->dimensions_count ?? $version->dimensions->count(),
            'approved_by' => $version->approved_by,
            'approval_reason' => $version->approval_reason,
            'effective_date' => $version->effective_date?->toDateString(),
            'published_at' => $version->published_at?->toIso8601String(),
            'deactivated_at' => $version->deactivated_at?->toIso8601String(),
            'deactivation_reason' => $version->deactivation_reason,
        ];
    }

    private function assertExpert(User $actor): void
    {
        if ($actor->hasRole('admin') || $actor->hasRole('university_admin')) {
            return;
        }

        throw new EvaluationException(
            'Only an administrator or an authorized domain expert may manage rubrics.',
            403,
            'RUBRIC_FORBIDDEN',
        );
    }

    private function assertDraft(Rubric $version): void
    {
        if ($version->status !== Rubric::STATUS_DRAFT) {
            throw new EvaluationException(
                'A published rubric version is immutable. Create a new version instead.',
                422,
                'RUBRIC_VERSION_IMMUTABLE',
                ['rubric_version_id' => $version->id, 'status' => $version->status],
            );
        }
    }

    private function assertPublishable(Rubric $version): void
    {
        $dimensions = $version->dimensions;

        if ($dimensions->isEmpty()) {
            throw new EvaluationException(
                'A rubric version needs at least one dimension before it can be published.',
                422,
                'RUBRIC_DIMENSIONS_REQUIRED',
            );
        }

        $missingMappings = [];
        $rangeProblems = [];
        $total = 0.0;

        foreach ($dimensions as $dimension) {
            $total += (float) $dimension->weight;

            if ($dimension->skillIds() === []) {
                $missingMappings[] = $dimension->name;
            }

            if ((float) $dimension->score_max <= (float) $dimension->score_min) {
                $rangeProblems[] = $dimension->name;
            }
        }

        if ($missingMappings !== []) {
            throw new EvaluationException(
                'Every rubric dimension must be mapped to at least one skill.',
                422,
                'RUBRIC_SKILL_MAPPING_REQUIRED',
                ['dimensions' => $missingMappings],
            );
        }

        if ($rangeProblems !== []) {
            throw new EvaluationException(
                'Every rubric dimension needs a score range where the maximum is greater than the minimum.',
                422,
                'RUBRIC_SCORE_RANGE_INVALID',
                ['dimensions' => $rangeProblems],
            );
        }

        $tolerance = (float) config('sprint5.rubrics.weight_tolerance', 0.001);

        if (abs($total - 1.0) > $tolerance) {
            throw new EvaluationException(
                'Rubric weights must be normalized so that they add up to 1.00.',
                422,
                'RUBRIC_WEIGHTS_NOT_NORMALIZED',
                ['total_weight' => round($total, 4)],
            );
        }
    }

    private function assertWeightsNotAllZero(array $dimensions): void
    {
        $total = array_sum(array_map(fn (array $dimension) => (float) $dimension['weight'], $dimensions));

        if ($dimensions !== [] && $total <= 0.0) {
            throw new EvaluationException(
                'Rubric weights cannot all be zero.',
                422,
                'RUBRIC_WEIGHTS_EMPTY',
            );
        }
    }

    private function normalizeDimensions(array $dimensions): array
    {
        if ($dimensions === []) {
            throw new EvaluationException(
                'A rubric version needs at least one dimension.',
                422,
                'RUBRIC_DIMENSIONS_REQUIRED',
            );
        }

        $normalized = [];
        $seen = [];

        foreach (array_values($dimensions) as $index => $dimension) {
            $name = (string) ($dimension['name'] ?? '');

            if (! in_array($name, Rubric::DIMENSIONS, true)) {
                throw new EvaluationException(
                    'The selected rubric dimension is not supported.',
                    422,
                    'RUBRIC_DIMENSION_UNSUPPORTED',
                    ['dimension' => $name, 'allowed' => Rubric::DIMENSIONS],
                );
            }

            if (isset($seen[$name])) {
                throw new EvaluationException(
                    'A rubric dimension can only appear once per version.',
                    422,
                    'RUBRIC_DIMENSION_DUPLICATED',
                    ['dimension' => $name],
                );
            }

            $seen[$name] = true;

            $skillIds = array_values(array_unique(array_map('intval', (array) ($dimension['skill_ids'] ?? []))));

            if ($skillIds !== []) {
                $existing = Skill::query()->whereIn('id', $skillIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
                $missing = array_values(array_diff($skillIds, $existing));

                if ($missing !== []) {
                    throw new EvaluationException(
                        'One or more mapped skills do not exist.',
                        422,
                        'RUBRIC_SKILL_NOT_FOUND',
                        ['skill_ids' => $missing],
                    );
                }
            }

            $scoreMin = (float) ($dimension['score_min'] ?? 0);
            $scoreMax = (float) ($dimension['score_max'] ?? 100);

            if ($scoreMax <= $scoreMin) {
                throw new EvaluationException(
                    'Every rubric dimension needs a score range where the maximum is greater than the minimum.',
                    422,
                    'RUBRIC_SCORE_RANGE_INVALID',
                    ['dimension' => $name],
                );
            }

            $normalized[] = [
                'name' => $name,
                'weight' => round((float) ($dimension['weight'] ?? 0), 4),
                'score_min' => $scoreMin,
                'score_max' => $scoreMax,
                'indicators' => array_values((array) ($dimension['indicators'] ?? [])),
                'order_index' => (int) ($dimension['order_index'] ?? $index),
                'skill_ids' => $skillIds,
            ];
        }

        return $normalized;
    }

    private function replaceDimensions(Rubric $rubric, array $dimensions): void
    {
        $rubric->dimensions()->delete();

        foreach ($dimensions as $dimension) {
            $created = $rubric->dimensions()->create([
                'name' => $dimension['name'],
                'weight' => $dimension['weight'],
                'score_min' => $dimension['score_min'],
                'score_max' => $dimension['score_max'],
                'indicators' => $dimension['indicators'],
                'order_index' => $dimension['order_index'],
                'skill_id' => $dimension['skill_ids'][0] ?? null,
            ]);

            if ($dimension['skill_ids'] !== []) {
                $created->skills()->sync($dimension['skill_ids']);
            }
        }
    }
}
