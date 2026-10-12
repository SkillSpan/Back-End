<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TalentCardResource;
use App\Http\Resources\TalentProfileResource;
use App\Services\Talent\TalentDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Talent Discovery — the company-facing list of discoverable students.
 *
 * Replaces the frontend's use of `GET /api/v1/recommendations` for this page.
 * `/recommendations` returns the CALLER's own stored PROJECT recommendations and
 * is gated on `role:learner`; it was never able to answer "which students may
 * this company see?". That endpoint is untouched by this class.
 *
 * Both routes sit behind
 * `auth:sanctum` + `account.active` + `role:company_admin` + `organization.approved`
 * (see routes/api.php). The last one matters: the prototype advertises "Verified
 * Company Access", and `EnsureOrganizationIsApproved` is what makes that true —
 * an account whose organization is still pending or rejected is refused.
 */
class TalentDiscoveryController extends Controller
{
    public function __construct(private readonly TalentDiscoveryService $talent) {}

    /**
     * GET /api/v1/talent
     *
     * Paginated, filtered list of students the caller is permitted to see.
     */
    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $page = $this->talent->paginate($request->user(), $this->validatedFilters($request));

        return response()->json([
            'success' => true,
            'message' => 'Discoverable talent retrieved successfully.',
            'data' => TalentCardResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'request_id' => $requestId,
        ]);
    }

    /**
     * GET /api/v1/talent/{student}
     *
     * One student's profile. The SAME visibility rules as the list are applied
     * here — never weaker — so a caller cannot reach a student by guessing an
     * id that the list would not have shown them.
     */
    public function show(Request $request, int $student): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $profile = $this->talent->profile($request->user(), $student);
        } catch (NotFoundHttpException) {
            // 404, never 403: a 403 would confirm that the id exists and turn
            // this endpoint into a membership oracle.
            return response()->json([
                'success' => false,
                'message' => 'No discoverable student with that id.',
                'request_id' => $requestId,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Talent profile retrieved successfully.',
            'data' => (new TalentProfileResource($profile))->resolve($request),
            'request_id' => $requestId,
        ]);
    }

    /**
     * GET /api/v1/talent/filters
     *
     * The filter values THIS caller can actually use. `career_role` is a free
     * string on the list route, but a client still has to know which slugs
     * exist — and `GET /api/v1/career-roles` is gated on `role:learner`, so a
     * company admin cannot read it. Only roles that can actually yield a
     * result for this caller are returned; a dropdown built from the whole
     * `career_roles` table would offer options that always come back empty.
     */
    public function filters(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Talent discovery filters retrieved successfully.',
            'data' => $this->talent->filters($request->user()),
            'request_id' => $this->requestId($request),
        ]);
    }

    /**
     * Validated query parameters.
     *
     * `sort` is validated against the whitelist rather than silently defaulted,
     * so a client that asks for an unsupported sort learns about it instead of
     * receiving a plausible-looking but differently-ordered page.
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'career_role' => ['nullable', 'string', 'max:255'],
            'min_readiness' => ['nullable', 'numeric', 'between:0,100'],
            'sort' => ['nullable', 'string', Rule::in(TalentDiscoveryService::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,'.TalentDiscoveryService::MAX_PER_PAGE],
        ]);
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }
}
