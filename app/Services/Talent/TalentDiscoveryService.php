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

/**
 * Talent Discovery — the company-facing list of students who have made
 * themselves visible.
 *
 * WHY THIS EXISTS: the frontend filled its Talent Discovery page from
 * `GET /api/v1/recommendations`, which answers a different question entirely
 * — that endpoint returns the CALLER's own stored project recommendations
 * (`recommendation` rows where `user_id` = caller and `type` = 'project'), and
 * it is gated on `role:learner`, so a company admin could never have reached
 * it. Nothing in the API listed talent.
 *
 * THE PERMISSION RULE is deliberately the SAME SHAPE as the one already in
 * `MentorStudentService::getPermittedStudents()`, because "which students may
 * this account see?" must have exactly one answer platform-wide:
 *
 *     visible  =  an active mentor connection with the viewer
 *              OR visibility = 'public'
 *              OR (visibility = 'organization_only' AND the student shares an
 *                  active organization membership with the viewer)
 *
 * Talent Discovery adds ONE gate the mentor path does not need, because a
 * company is a third party rather than a coach: `consent_given` must be true.
 *
 * ⚠️ `student_profiles.visibility` defaults to **'private'** and
 * `consent_given` defaults to **false**, so the list stays EMPTY until a
 * student actively opts in. That is the intended behaviour, not a bug — but it
 * means an empty response is the expected state on a fresh database, and it is
 * why the tests below always create the profile with an explicit visibility.
 */
class TalentDiscoveryService
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * `learner_skills.level` is validated as `between:0.00,5.00` in
     * SkillsController::store, so 5 is the ceiling the contract exposes. It is
     * reported alongside every level rather than assumed by the client, because
     * the prototype UI happened to draw a 4-dot scale.
     */
    public const MAX_SKILL_LEVEL = 5;

    /** Sorts the API accepts. 'match' was dropped: no talent match score is stored. */
    public const SORTS = ['readiness', 'recent'];

    public const DEFAULT_SORT = 'readiness';

    /** How many skills ride along on a card. The detail endpoint returns more. */
    private const CARD_SKILL_LIMIT = 3;

    private const DETAIL_SKILL_LIMIT = 20;

    private const PROJECT_LIMIT = 20;

    /**
     * The paginated card list. Items are already-shaped arrays, which is the
     * convention `StudentSummaryResource` established in this codebase.
     */
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

    /**
     * One student's detail payload.
     *
     * A student the viewer may not see is reported as **404, not 403**: a 403
     * would confirm that the id exists, which turns the endpoint into a
     * membership oracle. Every miss — unknown id, private profile, no consent,
     * another organization — answers identically.
     *
     * @throws NotFoundHttpException
     */
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

    /**
     * The `readiness_results.band` enum, so a client can label the `band` it
     * receives on a card without hardcoding the vocabulary.
     */
    public const READINESS_BANDS = [
        'foundation_needed',
        'developing',
        'moderate_readiness',
        'near_ready',
        'highly_ready',
    ];

    /**
     * The values the list's filters accept, so a client never has to hardcode
     * them.
     *
     * `career_roles` is deliberately NOT the whole taxonomy — it is the set that
     * can actually return a result for THIS caller, derived from the same
     * permitted-student query as the list. A dropdown built from the full
     * `career_roles` table would offer options that always come back empty.
     *
     * This exists because a company admin cannot read `GET /api/v1/career-roles`
     * (all three of its routes are `role:learner`), so without it the
     * `career_role` filter would be unusable without guessing slugs.
     */
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

    // -----------------------------------------------------------------
    // Query construction
    // -----------------------------------------------------------------

    /**
     * The permitted-student query, already filtered, sorted, and carrying the
     * latest readiness score/band as select aliases.
     */
    private function query(User $viewer, array $filters): Builder
    {
        $query = $this->permittedQuery($viewer);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'] ?? self::DEFAULT_SORT);

        // ⚠️ `select()` before `addSelect()`: an Eloquent builder whose select
        // list is non-empty does NOT prepend `*`, so without this the model
        // attributes would silently come back missing.
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
            // A suspended or soft-deleted account must not be discoverable even
            // if its profile was left behind.
            ->whereHas('user', fn (Builder $user) => $user->where('status', 'active'))
            ->where(function (Builder $visible) use ($connectedStudentIds, $organizationIds) {
                $visible->where('student_profiles.visibility', 'public');

                if ($connectedStudentIds !== []) {
                    $visible->orWhereIn('student_profiles.user_id', $connectedStudentIds);
                }

                // An empty $organizationIds makes this branch unsatisfiable,
                // which is correct: an account with no active organization
                // sees public students only.
                $visible->orWhere(function (Builder $orgScoped) use ($organizationIds) {
                    $orgScoped->where('student_profiles.visibility', 'organization_only')
                        ->whereHas('user.organizations', function ($orgQuery) use ($organizationIds) {
                            $orgQuery->whereIn('organizations.id', $organizationIds);
                        });
                });
            })
            // The extra gate: a company is a third party, so consent is required.
            ->where('student_profiles.consent_given', true);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.$search.'%';

            // "Name or track", matching the search box in the UI.
            $query->where(function (Builder $inner) use ($like) {
                $inner->whereHas('user', fn (Builder $user) => $user->where('name', 'like', $like))
                    ->orWhereHas('primaryCareerRole', fn (Builder $role) => $role->where('title', 'like', $like));
            });
        }

        $careerRole = $filters['career_role'] ?? null;

        if ($careerRole !== null && $careerRole !== '') {
            $query->whereHas('primaryCareerRole', function (Builder $role) use ($careerRole) {
                // Grouped, so the OR cannot leak into the surrounding AND chain.
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
            // Filtered through a correlated subquery rather than the select
            // alias: MySQL does not allow a select alias in WHERE.
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

        // A deterministic secondary sort is what keeps pagination stable — two
        // students with the same readiness must not swap pages between requests.
        $query->orderByDesc('student_profiles.id');
    }

    /**
     * The latest readiness row for this profile, as a scalar subquery.
     * `calculated_at` can repeat (several career roles in one run), hence the
     * `id` tiebreak.
     */
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

    // -----------------------------------------------------------------
    // Shaping
    // -----------------------------------------------------------------

    private function card(StudentProfile $profile, Collection $skillsByLearner): array
    {
        return $this->identity($profile) + [
            'top_skills' => $skillsByLearner->get($profile->user_id, collect())
                ->take(self::CARD_SKILL_LIMIT)
                ->values()
                ->all(),
        ];
    }

    /**
     * Fields shared by the card and the detail payload.
     *
     * Deliberately ABSENT: the student's email address. The prototype shows a
     * contact button, not an address, and a company reaching a learner through
     * the platform should go through a recorded contact request rather than
     * harvesting mailboxes from a list endpoint.
     */
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
            // Always true for a listed student — consent is an inclusion
            // condition — but reported explicitly so the client can drive the
            // contact affordance off the payload instead of assuming it.
            'contactable' => (bool) $profile->consent_given,
            'readiness' => [
                'score' => $score === null ? null : round((float) $score, 2),
                'band' => $profile->latest_readiness_band,
            ],
        ];
    }

    /**
     * Skills for every profile on the page, in ONE query per source table
     * rather than one per student, keyed by learner id.
     */
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

    /**
     * The projects this student was on, from team membership.
     *
     * ⚠️ Only what the schema actually stores. The prototype's project card also
     * shows free-text `outcomes` and a per-project skill list; neither exists
     * per team member, so neither is invented here.
     */
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

    /**
     * A company-facing evaluation summary: finalized evaluations on projects
     * this student worked on, averaged.
     *
     * Deliberately WITHHELD, and this is a decision to keep: the evaluator's
     * identity, per-dimension comments, and `moderation_reason`. Those are
     * internal review material; the prototype's "Evaluator Notes" box and
     * "reviewed by" line should be filled from the count and the date instead.
     */
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
            // `rubric_dimensions.name` is a fixed enum — quality, commitment,
            // communication, collaboration, creativity, problem_solving,
            // delivery — so the payload carries the KEY and the client owns the
            // display label and its translation.
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
