<?php

namespace App\Services\Talent;

use App\Models\CareerRole;
use App\Models\Evaluation;
use App\Models\LearnerSkill;
use App\Models\MentorStudentConnection;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\ReadinessResult;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TalentDiscoveryService
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public const MAX_SKILL_LEVEL = 5;

    public const SORTS = ['readiness', 'recent'];

    public const DEFAULT_SORT = 'readiness';

    private const CARD_SKILL_LIMIT = 3;

    private const DETAIL_SKILL_LIMIT = 20;

    private const PROJECT_LIMIT = 20;

    public function paginate(User $viewer, array $filters): LengthAwarePaginator
    {
        $profiles = $this->query($viewer, $filters)
            ->with(['user', 'primaryCareerRole'])
            ->paginate($this->perPage($filters), ['*'], 'page', $this->page($filters));

        $skills = $this->skillsFor($profiles->getCollection());

        $profiles->setCollection(
            $profiles->getCollection()->map(
                fn (StudentProfile $profile) => $this->card($profile, $skills)
            )
        );

        return $profiles;
    }

    public function visibleStudentIds(User $viewer): array
    {
        return $this->permittedQuery($viewer)
            ->with('user:id')
            ->get()
            ->map(fn (StudentProfile $profile) => (int) $profile->user_id)
            ->values()
            ->all();
    }

    public function profile(User $viewer, int $studentId): array
    {
        $profile = $this->query($viewer, [])
            ->with(['user', 'primaryCareerRole'])
            ->where('student_profiles.user_id', $studentId)
            ->first();

        if ($profile === null) {
            throw new NotFoundHttpException('No discoverable student with that id.');
        }

        $skills = $this->skillsFor(collect([$profile]))->get($studentId, collect());

        return $this->identity($profile) + [
            'bio' => $profile->bio,
            'interests' => $profile->interests ?? [],
            'academic_level' => $profile->academic_level,
            'expected_graduation' => $profile->expected_graduation,
            'graduation_status' => (bool) $profile->graduation_status,
            'skills' => array_slice($skills->all(), 0, self::DETAIL_SKILL_LIMIT),
            'projects' => $this->projects($profile),
            'evaluation' => $this->evaluation($profile),
        ];
    }

    public const READINESS_BANDS = [
        'foundation_needed',
        'developing',
        'moderate_readiness',
        'near_ready',
        'highly_ready',
    ];

    public function filters(User $viewer): array
    {
        $careerRoles = CareerRole::query()
            ->whereIn('id', function ($sub) use ($viewer) {
                $sub->select('student_profiles.primary_career_role_id')
                    ->from('student_profiles')
                    ->whereNotNull('student_profiles.primary_career_role_id')
                    ->whereIn(
                        'student_profiles.id',
                        $this->permittedQuery($viewer)->select('student_profiles.id'),
                    );
            })
            ->orderBy('title')
            ->get(['id', 'title', 'slug'])
            ->map(fn (CareerRole $role) => [
                'id' => (int) $role->id,
                'title' => $role->title,
                'slug' => $role->slug,
            ])
            ->values()
            ->all();

        return [
            'career_roles' => $careerRoles,
            'readiness_bands' => self::READINESS_BANDS,
        ];
    }

    private function query(User $viewer, array $filters): Builder
    {
        $query = $this->permittedQuery($viewer);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'] ?? self::DEFAULT_SORT);

        return $query->select('student_profiles.*')->addSelect([
            'latest_readiness_score' => $this->latestReadinessValue('score'),
            'latest_readiness_band' => $this->latestReadinessValue('band'),
        ]);
    }

    private function permittedQuery(User $viewer): Builder
    {
        $connectedStudentIds = MentorStudentConnection::forMentor($viewer->id)
            ->active()
            ->pluck('student_id')
            ->all();

        $organizationIds = $viewer->organizations()
            ->wherePivot('status', 'active')
            ->pluck('organizations.id')
            ->all();

        return StudentProfile::query()
            ->whereHas('user', fn (Builder $user) => $user->where('status', 'active'))
            ->where(function (Builder $visible) use ($connectedStudentIds, $organizationIds) {
                $visible->where('student_profiles.visibility', 'public');

                if ($connectedStudentIds !== []) {
                    $visible->orWhereIn('student_profiles.user_id', $connectedStudentIds);
                }

                $visible->orWhere(function (Builder $orgScoped) use ($organizationIds) {
                    $orgScoped->where('student_profiles.visibility', 'organization_only')
                        ->whereHas('user.organizations', function ($orgQuery) use ($organizationIds) {
                            $orgQuery->whereIn('organizations.id', $organizationIds);
                        });
                });
            })
            ->where('student_profiles.consent_given', true);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function (Builder $inner) use ($like) {
                $inner->whereHas('user', fn (Builder $user) => $user->where('name', 'like', $like))
                    ->orWhereHas('primaryCareerRole', fn (Builder $role) => $role->where('title', 'like', $like));
            });
        }

        $careerRole = $filters['career_role'] ?? null;

        if ($careerRole !== null && $careerRole !== '') {
            $query->whereHas('primaryCareerRole', function (Builder $role) use ($careerRole) {
                $role->where(function (Builder $match) use ($careerRole) {
                    $match->where('career_roles.slug', (string) $careerRole);

                    if (ctype_digit((string) $careerRole)) {
                        $match->orWhere('career_roles.id', (int) $careerRole);
                    }
                });
            });
        }

        $minReadiness = $filters['min_readiness'] ?? null;

        if ($minReadiness !== null && $minReadiness !== '') {
            $query->whereRaw(
                '('.$this->latestReadinessSql('score').') >= ?',
                [(float) $minReadiness],
            );
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'recent' => $query->orderByDesc('student_profiles.updated_at'),
            default => $query->orderByDesc('latest_readiness_score'),
        };

        $query->orderByDesc('student_profiles.id');
    }

    private function latestReadinessValue(string $column): Builder
    {
        return ReadinessResult::query()
            ->select("readiness_results.{$column}")
            ->whereColumn('readiness_results.student_profile_id', 'student_profiles.id')
            ->orderByDesc('readiness_results.calculated_at')
            ->orderByDesc('readiness_results.id')
            ->limit(1);
    }

    private function latestReadinessSql(string $column): string
    {
        return 'select readiness_results.'.$column
            .' from readiness_results'
            .' where readiness_results.student_profile_id = student_profiles.id'
            .' order by readiness_results.calculated_at desc, readiness_results.id desc'
            .' limit 1';
    }

    private function perPage(array $filters): int
    {
        $requested = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        return max(1, min($requested ?: self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE));
    }

    private function page(array $filters): int
    {
        return max(1, (int) ($filters['page'] ?? 1));
    }

    private function card(StudentProfile $profile, Collection $skillsByLearner): array
    {
        return $this->identity($profile) + [
            'top_skills' => $skillsByLearner->get($profile->user_id, collect())
                ->take(self::CARD_SKILL_LIMIT)
                ->values()
                ->all(),
        ];
    }

    private function identity(StudentProfile $profile): array
    {
        $score = $profile->latest_readiness_score;

        return [
            'id' => (int) $profile->user_id,
            'name' => $profile->user?->name,
            'career_role' => $profile->primaryCareerRole === null ? null : [
                'id' => (int) $profile->primaryCareerRole->id,
                'title' => $profile->primaryCareerRole->title,
                'slug' => $profile->primaryCareerRole->slug,
            ],
            'specialization' => $profile->specialization,
            'university_name' => $profile->university_name,
            'career_status' => $profile->career_status,
            'enrollment_status' => $profile->enrollment_status,
            'availability' => $profile->availability,
            'preferred_work_type' => $profile->preferred_work_type,
            'completeness_percent' => (int) $profile->completeness_percent,
            'visibility' => $profile->visibility,
            'contactable' => (bool) $profile->consent_given,
            'readiness' => [
                'score' => $score === null ? null : round((float) $score, 2),
                'band' => $this->bandFor($score === null ? null : (float) $score, $profile->latest_readiness_band),
            ],
        ];
    }

    private function bandFor(?float $score, ?string $band): ?string
    {
        if ($band !== null && $band !== '') {
            return $band;
        }

        if ($score === null) {
            return null;
        }

        foreach (config('readiness.bands', []) as $name => $range) {
            if ($score >= (float) $range['min'] && $score <= (float) $range['max']) {
                return $name;
            }
        }

        return null;
    }

    private function skillsFor(Collection $profiles): Collection
    {
        $userIds = $profiles->pluck('user_id')->unique()->values()->all();

        if ($userIds === []) {
            return collect();
        }

        $profileIds = $profiles->pluck('id')->all();

        $evidence = SkillEvidence::query()
            ->whereIn('student_profile_id', $profileIds)
            ->selectRaw(
                'student_profile_id, skill_id, COUNT(*) as evidence_count,'
                .' SUM(CASE WHEN verification_status = ? THEN 1 ELSE 0 END) as verified_count',
                ['verified'],
            )
            ->groupBy('student_profile_id', 'skill_id')
            ->get()
            ->keyBy(fn ($row) => $row->student_profile_id.':'.$row->skill_id);

        $profileIdByUser = $profiles->pluck('id', 'user_id');

        return LearnerSkill::query()
            ->whereIn('learner_id', $userIds)
            ->with('skill:id,name,category')
            ->orderByDesc('level')
            ->orderBy('skill_id')
            ->get()
            ->groupBy('learner_id')
            ->map(function (Collection $rows) use ($evidence, $profileIdByUser) {
                $profileId = $profileIdByUser[$rows->first()->learner_id] ?? null;

                return $rows->map(function (LearnerSkill $row) use ($evidence, $profileId) {
                    $counts = $evidence->get($profileId.':'.$row->skill_id);

                    return [
                        'name' => $row->skill?->name,
                        'category' => $row->skill?->category,
                        'level' => round((float) $row->level, 2),
                        'max_level' => self::MAX_SKILL_LEVEL,
                        'verified' => ((int) ($counts->verified_count ?? 0)) > 0,
                        'evidence_count' => (int) ($counts->evidence_count ?? 0),
                    ];
                })->values();
            });
    }

    private function projects(StudentProfile $profile): array
    {
        return ProjectTeamMember::query()
            ->where('user_id', $profile->user_id)
            ->whereIn('assignment_state', ['active', 'completed'])
            ->with(['team.project:id,title,type,status,organization_id', 'team.project.organization:id,name'])
            ->orderByDesc('id')
            ->limit(self::PROJECT_LIMIT)
            ->get()
            ->map(fn (ProjectTeamMember $member) => [
                'id' => $member->team?->project?->id,
                'title' => $member->team?->project?->title,
                'type' => $member->team?->project?->type,
                'role' => $member->project_role,
                'state' => $member->assignment_state,
                'sponsor_org' => $member->team?->project?->organization?->name,
                'joined_at' => $member->joined_at?->toDateString(),
                'left_at' => $member->left_at?->toDateString(),
            ])
            ->filter(fn (array $project) => $project['id'] !== null)
            ->values()
            ->all();
    }

    private function evaluation(StudentProfile $profile): array
    {
        $empty = [
            'authorized' => false,
            'overall_rating' => null,
            'criteria_scores' => [],
            'evaluations_count' => 0,
            'reviewed_at' => null,
        ];

        $projectIds = ProjectTeam::query()
            ->whereHas('members', fn (Builder $member) => $member->where('user_id', $profile->user_id))
            ->pluck('project_id')
            ->unique()
            ->values()
            ->all();

        if ($projectIds === []) {
            return $empty;
        }

        $evaluations = Evaluation::query()
            ->whereIn('project_id', $projectIds)
            ->where('status', 'finalized')
            ->with('dimensionScores.dimension:id,name')
            ->get();

        if ($evaluations->isEmpty()) {
            return $empty;
        }

        $criteria = $evaluations
            ->flatMap(fn (Evaluation $evaluation) => $evaluation->dimensionScores)
            ->groupBy(fn ($score) => $score->dimension?->name ?? 'unspecified')
            ->map(fn (Collection $scores, string $key) => [
                'key' => $key,
                'score' => round((float) $scores->avg('score'), 2),
            ])
            ->values()
            ->all();

        $latest = $evaluations->max('finalized_at');

        return [
            'authorized' => true,
            'overall_rating' => round((float) $evaluations->avg('overall_score'), 2),
            'criteria_scores' => $criteria,
            'evaluations_count' => $evaluations->count(),
            'reviewed_at' => $latest === null ? null : Carbon::parse($latest)->toDateString(),
        ];
    }
}
