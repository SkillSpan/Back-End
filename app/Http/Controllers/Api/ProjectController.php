<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
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

        $organizationId = DB::table('organization_members')
            ->where('user_id', $user->id)
            ->value('organization_id');

        $query = Project::query()
            ->with([
                'organization:id,title',
                'requiredSkills.skill:id,name',
            ]);

        $query->where('status', 'open')
            ->whereNotIn('confidentiality', ['restricted'])
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