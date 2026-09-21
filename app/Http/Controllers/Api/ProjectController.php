<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Projects\ProjectAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    private const PROJECT_WITH = [
        'organization:id,title',
        'requiredSkills.skill:id,name',
    ];

    public function __construct(
        private readonly ProjectAvailabilityService $availabilityService = new ProjectAvailabilityService(),
    ) {}

    public function index(Request $request): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());

        $user = $request->user();
        $studentProfile = $user->studentProfile;

        if (! $studentProfile) {
            return $this->errorResponse(
                'STUDENT_PROFILE_NOT_FOUND',
                'The authenticated learner does not have a student profile.',
                422,
                $requestId,
            );
        }

        $organizationId = $this->learnerOrganizationId($user);

        $query = $this->accessibleProjectsQuery($organizationId, self::PROJECT_WITH);

        /** @var array<string, mixed> $filters */
        $filters = $this->validatedFilters($request, $requestId);

        if ($filters instanceof JsonResponse) {
            return $filters;
        }

        // Keyword search across the visible project text fields.
        if (! empty($filters['search'])) {
            $like = $this->likePattern($filters['search']);

            $query->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('objectives', 'like', $like);
            });
        }

        // Exact-match filters on columns that exist in the current schema.
        foreach (['type', 'domain', 'work_mode'] as $column) {
            if (array_key_exists($column, $filters) && $filters[$column] !== null) {
                $query->where($column, $filters[$column]);
            }
        }

        if (array_key_exists('difficulty', $filters) && $filters['difficulty'] !== null) {
            $query->where('difficulty', $filters['difficulty']);
        }

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        // Required-skill filters: the project must require ALL of the
        // supplied skill ids (joined through the pivot table).
        if (! empty($filters['skill_ids'])) {
            $skillIds = $filters['skill_ids'];

            $query->whereHas('requiredSkills', function ($q) use ($skillIds) {
                $q->whereIn('skill_id', $skillIds);
            }, '=', count($skillIds));

            if (array_key_exists('minimum_level', $filters) && $filters['minimum_level'] !== null) {
                $query->whereHas('requiredSkills', function ($q) use ($skillIds, $filters) {
                    $q->whereIn('skill_id', $skillIds)
                        ->where('minimum_level', '>=', $filters['minimum_level']);
                }, '=', count($skillIds));
            }
        }

        $projects = $query->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Accessible projects retrieved successfully.',
            'data' => ProjectResource::collection($projects),
            'request_id' => $requestId,
        ]);
    }

    /**
     * GET /api/v1/projects/{project}
     *
     * Returns the details of one project the authenticated learner is
     * authorized to see. The SAME access/availability rules as the
     * catalog are applied here (never weaker), so a learner cannot
     * bypass catalog restrictions by supplying a project ID directly.
     */
    public function show(Request $request, int $project): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());

        $user = $request->user();
        $studentProfile = $user->studentProfile;

        if (! $studentProfile) {
            return $this->errorResponse(
                'STUDENT_PROFILE_NOT_FOUND',
                'The authenticated learner does not have a student profile.',
                422,
                $requestId,
            );
        }

        $accessibleProject = $this->getSecureAccessibleProject($project, $user);

        if (! $accessibleProject) {
            $exists = Project::query()->whereKey($project)->exists();

            return $this->errorResponse(
                $exists
                    ? 'PROJECT_UNAUTHORIZED'
                    : 'PROJECT_NOT_FOUND',
                $exists
                    ? 'The requested project is not available to this learner.'
                    : 'The requested project does not exist.',
                $exists ? 403 : 404,
                $requestId,
                ['project_id' => $project],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Project details retrieved successfully.',
            'data' => new ProjectResource($accessibleProject),
            'request_id' => $requestId,
        ]);
    }

    /**
     * Retrieve a project that the authenticated learner is authorized to
     * view AND that satisfies availability rules. The query enforces the
     * same catalog-level restrictions (status, deadline, end_date,
     * confidentiality) so a learner cannot bypass access by supplying
     * a project ID directly.
     */
    private function getSecureAccessibleProject(int $projectId, $user): ?Project
    {
        $organizationId = $this->learnerOrganizationId($user);

        $project = $this->accessibleProjectsQuery($organizationId, self::PROJECT_WITH)
            ->whereKey($projectId)
            ->first();

        if ($project && ! $this->availabilityService->isAvailable($project)) {
            return null;
        }

        return $project;
    }

    /**
     * The learner's own organization id from the membership pivot, or
     * null when the learner is not a member of any organization.
     */
    private function learnerOrganizationId($user): ?int
    {
        $organizationId = DB::table('organization_members')
            ->where('user_id', $user->id)
            ->value('organization_id');

        return $organizationId !== null ? (int) $organizationId : null;
    }

    /**
     * Reusable base query enforcing the project catalog access rules:
     * only available projects (status = open, application_deadline not
     * expired) with a valid end_date, visible publicly (or restricted
     * only to the learner's own org).
     *
     * The availability portion mirrors ProjectAvailabilityService::check();
     * the end_date and confidentiality filters are authorization rules
     * that remain in the controller.
     *
     * @param  array<int, string>  $with
     */
    private function accessibleProjectsQuery(?int $organizationId, array $with = []): \Illuminate\Database\Eloquent\Builder
    {
        $query = Project::query();

        if ($with !== []) {
            $query->with($with);
        }

        return $query->where('status', 'open')
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now()->toDateString());
            })
            ->where(function ($q) {
                $q->whereNull('application_deadline')
                    ->orWhere('application_deadline', '>=', now()->toDateString());
            })
            ->where(function ($subQ) use ($organizationId) {
                $subQ->where('confidentiality', 'public')
                    ->orWhere(function ($innerQ) use ($organizationId) {
                        $innerQ->where('confidentiality', 'restricted')
                            ->where('organization_id', $organizationId);
                    });
            });
    }

    private function likePattern(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)).'%';
    }

    /**
     * Validate the supported project-catalog filter query parameters.
     * Returns the validated array on success, or a JsonResponse with the
     * standard project VALIDATION_ERROR contract on failure.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function validatedFilters(Request $request, string $requestId): array|JsonResponse
    {
        $validator = validator($request->query(), [
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:simulation,company_sponsored'],
            'domain' => ['nullable', 'string', 'max:100'],
            'work_mode' => ['nullable', 'string', 'max:50'],
            'difficulty' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'organization_id' => ['nullable', 'integer', 'gt:0', 'exists:organizations,id'],
            'skill_ids' => ['nullable', 'array'],
            'skill_ids.*' => ['integer', 'gt:0', 'exists:skills,id'],
            'minimum_level' => ['nullable', 'numeric', 'min:0', 'max:5'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'The request could not be processed.',
                422,
                $requestId,
                ['errors' => $validator->errors()->messages()],
            );
        }

        return $validator->validated();
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        string $requestId,
        array $details = [],
    ): JsonResponse {
        $payload = [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return response()->json($payload, $status, ['X-Request-ID' => $requestId]);
    }
}