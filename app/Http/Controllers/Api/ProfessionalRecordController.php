<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Api\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluation\StoreRecordShareRequest;
use App\Http\Requests\Evaluation\UpdateRecordVisibilityRequest;
use App\Models\ProfessionalRecordItem;
use App\Models\ProfessionalRecordShareLink;
use App\Services\Evaluation\RecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ProfessionalRecordController extends Controller
{
    use RespondsWithApi;

    public function __construct(private readonly RecordService $records) {}

    public function show(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            return $this->success(
                'Professional record retrieved successfully.',
                ['record' => $this->records->record($request->user())],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function updateVisibility(UpdateRecordVisibilityRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $visibility = $this->records->updateVisibility($request->user(), $request->validated()['sections']);

            return $this->success(
                'Record visibility updated successfully.',
                ['visibility' => $visibility],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function shareLinks(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $links = $this->records->shareLinks($request->user());

            return $this->success(
                'Share links retrieved successfully.',
                [
                    'share_links' => $links->map(fn (ProfessionalRecordShareLink $link) => $this->records->shareLinkPayload($link))->values()->all(),
                ],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function createShareLink(StoreRecordShareRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $result = $this->records->share(
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Share link created successfully. Store the token now, it is only shown once.',
                $result,
                201,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function revokeShareLink(Request $request, int $link): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = ProfessionalRecordShareLink::query()->find($link);

        if (! $model) {
            return $this->notFound('RECORD_SHARE_NOT_FOUND', 'That share link does not exist.', ['share_link_id' => $link], $requestId);
        }

        try {
            $revoked = $this->records->revokeShare(
                $request->user(),
                $model,
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Share link revoked successfully.',
                ['share_link' => $this->records->shareLinkPayload($revoked)],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['share_link_id' => $model->id]);
        }
    }

    public function verification(Request $request, int $item): JsonResponse
    {
        $requestId = $this->requestId($request);

        $model = ProfessionalRecordItem::query()
            ->whereKey($item)
            ->where('student_profile_id', $request->user()->studentProfile?->id)
            ->first();

        if (! $model) {
            return $this->notFound('RECORD_ITEM_NOT_FOUND', 'That record item does not exist.', ['record_item_id' => $item], $requestId);
        }

        try {
            $verification = $this->records->ensureVerification($model);

            return $this->success(
                'Verification reference created successfully.',
                [
                    'verification' => [
                        'reference' => $verification->reference,
                        'url' => '/verify/'.$verification->reference,
                        'record_item_id' => $verification->record_item_id,
                    ],
                ],
                201,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['record_item_id' => $model->id]);
        }
    }

    public function export(Request $request)
    {
        $requestId = $this->requestId($request);

        try {
            $raw = $request->query('sections');
            $sections = is_array($raw)
                ? $raw
                : ($raw === null ? [] : array_values(array_filter(array_map('trim', explode(',', (string) $raw)))));

            $export = $this->records->export($request->user(), $sections);

            if ($request->query('format') === 'json') {
                return response()->json([
                    'success' => true,
                    'message' => 'Professional record export generated successfully.',
                    'data' => ['export' => $export],
                    'request_id' => $requestId,
                ], 200, ['X-Request-ID' => $requestId]);
            }

            return response($this->records->renderExportHtml($export), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Disposition' => 'inline; filename="skillspan-professional-record.html"',
                'X-Request-ID' => $requestId,
            ]);
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function publicRecord(Request $request, string $token): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            return $this->success(
                'Shared professional record retrieved successfully.',
                ['record' => $this->records->publicRecord($token, $request->ip(), $request->userAgent())],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function verify(Request $request, string $reference): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            return $this->success(
                'Verification reference resolved successfully.',
                ['verification' => $this->records->verify($reference)],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }
}
