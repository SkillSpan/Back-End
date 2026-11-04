<?php

namespace App\Http\Controllers\Api\Company;

use App\Exceptions\TalentException;
use App\Http\Controllers\Api\Company\Concerns\ResolvesCompanyOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompareTalentRequest;
use App\Http\Requests\StoreTalentContactRequest;
use App\Http\Requests\StoreTalentListCandidateRequest;
use App\Http\Requests\StoreTalentListRequest;
use App\Http\Requests\StoreTalentNoteRequest;
use App\Http\Resources\TalentListResource;
use App\Services\Talent\TalentEngagementService;
use App\Support\SafeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The company's talent engagement surface: compare, shortlists, notes and
 * contact requests.
 *
 * These sit under `/api/v1/talent/...`, beside the discovery endpoints, because
 * that is where a client looks for them and what the requested contract names.
 * They share the discovery group's three gates (token, approved organization,
 * company role), so the whole talent surface refuses a pending or non-company
 * account identically.
 *
 * The organization is always resolved from the caller's admin membership via
 * the trait — never from a request parameter — so no id in the body can reach
 * another company's lists.
 */
class TalentEngagementController extends Controller
{
    use ResolvesCompanyOrganization;

    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly TalentEngagementService $engagement,
    ) {}

    /**
     * POST /api/v1/company/talent/compare
     */
    public function compare(CompareTalentRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        try {
            $data = $this->engagement->compare(
                $request->user(),
                $request->validated()['student_ids'],
            );
        } catch (TalentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Candidates compared successfully.',
            'data' => $data,
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    // -----------------------------------------------------------------
    // Shortlists
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/company/talent/lists
     */
    public function indexLists(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        $lists = $this->engagement->listsFor($organization, $this->perPage($request));

        return response()->json([
            'success' => true,
            'message' => 'Talent lists retrieved successfully.',
            'data' => TalentListResource::collection($lists->items())->resolve($request),
            'meta' => $this->meta($lists),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * POST /api/v1/company/talent/lists
     */
    public function storeList(StoreTalentListRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        try {
            $list = $this->engagement->createList($organization, $request->user(), $request->validated());
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id]);
        }

        return (new TalentListResource($list))
            ->additional([
                'success' => true,
                'message' => 'Talent list created successfully.',
                'request_id' => $requestId,
            ])
            ->response()
            ->setStatusCode(201)
            ->header('X-Request-ID', $requestId);
    }

    /**
     * GET /api/v1/company/talent/lists/{list}
     */
    public function showList(Request $request, int $list): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        try {
            $model = $this->engagement->findList($organization, $list);
        } catch (TalentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }

        return (new TalentListResource($model))
            ->additional([
                'success' => true,
                'message' => 'Talent list retrieved successfully.',
                'request_id' => $requestId,
            ])
            ->response()
            ->setStatusCode(200)
            ->header('X-Request-ID', $requestId);
    }

    /**
     * PATCH /api/v1/talent/lists/{list}
     *
     * Rename a list or change its description. Both fields optional; a body
     * with neither is a validation error rather than a silent no-op, because a
     * caller who sent nothing meant to change something.
     */
    public function updateList(Request $request, int $list): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        $validator = validator($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
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

        $data = $validator->validated();

        if ($data === []) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'The request could not be processed.',
                422,
                $requestId,
                ['errors' => ['name' => ['Send at least one of name or description.']]],
            );
        }

        try {
            $model = $this->engagement->updateList($organization, $list, $data);
        } catch (TalentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }

        return (new TalentListResource($model))
            ->additional([
                'success' => true,
                'message' => 'Talent list updated successfully.',
                'request_id' => $requestId,
            ])
            ->response()
            ->setStatusCode(200)
            ->header('X-Request-ID', $requestId);
    }

    /**
     * DELETE /api/v1/talent/lists/{list}
     */
    public function destroyList(Request $request, int $list): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        try {
            $this->engagement->deleteList($organization, $list);
        } catch (TalentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }

        return response()->json([
            'success' => true,
            'message' => 'Talent list deleted successfully.',
            'data' => ['id' => $list, 'deleted' => true],
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * DELETE /api/v1/talent/lists/{list}/candidates/{student}
     */
    public function destroyListCandidate(Request $request, int $list, int $student): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        try {
            $this->engagement->removeCandidate($organization, $list, $student);
        } catch (TalentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }

        return response()->json([
            'success' => true,
            'message' => 'Candidate removed from the list.',
            'data' => ['student_id' => $student, 'removed' => true],
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * GET /api/v1/talent/funnel
     *
     * saved → contacted → responded, counted per person.
     */
    public function funnel(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Talent funnel retrieved successfully.',
            'data' => $this->engagement->funnel($organization),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * POST /api/v1/company/talent/lists/{list}/candidates
     *
     * 201 on a new row, 200 when the candidate was already on the list — the
     * second press of an "add to shortlist" button is not an error.
     */
    public function storeListCandidate(StoreTalentListCandidateRequest $request, int $list): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        $validated = $request->validated();

        try {
            $result = $this->engagement->addCandidate(
                $organization,
                $request->user(),
                $list,
                (int) $validated['student_id'],
                $validated['note'] ?? null,
            );
        } catch (TalentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'talent_list_id' => $list]);
        }

        return response()->json([
            'success' => true,
            'message' => $result['already_listed']
                ? 'Candidate is already on this list.'
                : 'Candidate added to the list.',
            'data' => [
                'id' => $result['candidate']->id,
                'student_id' => $result['candidate']->student_id,
                'name' => $result['candidate']->student?->name,
                'note' => $result['candidate']->note,
                'already_listed' => $result['already_listed'],
            ],
            'request_id' => $requestId,
        ], $result['already_listed'] ? 200 : 201, ['X-Request-ID' => $requestId]);
    }

    // -----------------------------------------------------------------
    // Notes
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/company/talent/candidates/{student}/notes
     */
    public function indexNotes(Request $request, int $student): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        try {
            $notes = $this->engagement->notesFor(
                $organization,
                $request->user(),
                $student,
                $this->perPage($request),
            );
        } catch (TalentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }

        return response()->json([
            'success' => true,
            'message' => 'Candidate notes retrieved successfully.',
            'data' => $notes->getCollection()->map(fn ($note) => [
                'id' => $note->id,
                'body' => $note->body,
                'author' => $note->author === null ? null : [
                    'id' => $note->author->id,
                    'name' => $note->author->name,
                ],
                'created_at' => $note->created_at?->toIso8601String(),
            ])->all(),
            'meta' => $this->meta($notes),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * POST /api/v1/company/talent/candidates/{student}/notes
     */
    public function storeNote(StoreTalentNoteRequest $request, int $student): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        try {
            $note = $this->engagement->addNote(
                $organization,
                $request->user(),
                $student,
                (string) $request->validated()['body'],
            );
        } catch (TalentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'student_id' => $student]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Note added successfully.',
            'data' => [
                'id' => $note->id,
                'body' => $note->body,
                'author' => $note->author === null ? null : [
                    'id' => $note->author->id,
                    'name' => $note->author->name,
                ],
                'created_at' => $note->created_at?->toIso8601String(),
            ],
            'request_id' => $requestId,
        ], 201, ['X-Request-ID' => $requestId]);
    }

    // -----------------------------------------------------------------
    // Contact
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/company/talent/candidates/{student}/contact
     *
     * Records the request and returns it. No address is read or returned: the
     * learner decides whether to answer.
     */
    public function storeContact(StoreTalentContactRequest $request, int $student): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        try {
            $contact = $this->engagement->requestContact(
                $organization,
                $request->user(),
                $student,
                (string) $request->validated()['message'],
            );
        } catch (TalentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'student_id' => $student]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Contact request sent to the candidate.',
            'data' => [
                'id' => $contact->id,
                'student_id' => $contact->student_id,
                'candidate_name' => $contact->student?->name,
                'message' => $contact->message,
                'status' => $contact->status,
                'created_at' => $contact->created_at?->toIso8601String(),
            ],
            'request_id' => $requestId,
        ], 201, ['X-Request-ID' => $requestId]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    /**
     * @return array<string, int>
     */
    private function meta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private function organizationDenied(Request $request, string $requestId): JsonResponse
    {
        $denial = $this->organizationDenial($request);

        return $this->errorResponse(
            $denial['code'],
            $denial['code'] === 'NO_ORGANIZATION'
                ? 'This account is not linked to any organization.'
                : 'Only an organization admin can manage talent.',
            403,
            $requestId,
        );
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }

    private function unexpected(Throwable $e, string $requestId, array $context = []): JsonResponse
    {
        Log::error('Talent engagement request failed.', array_merge([
            'request_id' => $requestId,
            'failure_reason' => SafeLog::reason($e),
        ], $context));

        return $this->errorResponse(
            'TALENT_REQUEST_FAILED',
            'The talent request could not be completed.',
            500,
            $requestId,
        );
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        string $requestId,
        array $details = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
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
