<?php

namespace App\Http\Controllers\Api;

use App\Events\SkillDataChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListEvidenceRequest;
use App\Http\Requests\StoreEvidenceRequest;
use App\Models\Skill;
use App\Models\SkillEvidence;
use App\Services\Skills\SkillEvaluationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EvidenceController extends Controller
{
    public function __construct(
        private readonly SkillEvaluationService $skillEvaluationService
    ) {}

    public function store(StoreEvidenceRequest $request)
    {

        $studentProfile = $request->user()->studentProfile;

        if (! $studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'You must complete your student profile before submitting evidence.',
            ], 422);
        }

        $skillId = $request->skill_id;

        $declaredType = $request->declaredEvidenceType();

        if ($request->filled('evidence_url')) {
            $reference = $request->evidence_url;
            $evidenceType = $declaredType;
        } elseif ($request->hasFile('evidence_file')) {
            $reference = $request->file('evidence_file')->store('evidence');
            $evidenceType = $declaredType ?? SkillEvidence::TYPE_DOCUMENT;
        } else {
            $reference = null;
            $evidenceType = $declaredType;
        }

        $duplicate = SkillEvidence::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('skill_id', $skillId)
            ->where('source', SkillEvidence::LEARNER_SUBMISSION_SOURCE)
            ->when(
                $evidenceType === null,
                fn ($query) => $query->whereNull('evidence_type'),
                fn ($query) => $query->where('evidence_type', $evidenceType),
            )
            ->where('reference', $reference)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate evidence already exists for this skill.',
            ], 409);
        }

        $declaredLevel = $request->filled('level')
            ? round((float) $request->input('level'), 2)
            : 0.0;

        $evidence = SkillEvidence::create([
            'student_profile_id' => $studentProfile->id,
            'skill_id' => $skillId,
            'source' => SkillEvidence::LEARNER_SUBMISSION_SOURCE,
            'evidence_type' => $evidenceType,
            'value' => $declaredLevel,
            'normalized_value' => $declaredLevel,
            'reference' => $reference,

            'description' => $request->input('description'),

            'evidence_date' => $request->filled('evidence_date')
                ? $request->evidence_date
                : now()->toDateString(),

            'verification_status' => 'pending',
            'recency_factor' => 1.00,
        ]);

        $skill = Skill::find($skillId);

        if ($skill) {
            $this->skillEvaluationService->recalculate(
                $studentProfile,
                $skill
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Evidence submitted successfully, pending review.',
            'data' => $evidence,
        ], 201);
    }

    public function index(ListEvidenceRequest $request)
    {
        $studentProfile = $request->user()->studentProfile;

        if (! $studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'You must complete your student profile first.',
            ], 422);
        }

        $status = $request->validated('status');

        $evidence = SkillEvidence::where(
            'student_profile_id',
            $studentProfile->id
        )
            ->when(
                $status !== null,
                fn ($query) => $query->where('verification_status', $status),
            )
            ->with([
                'skill',
                'reviewer',
            ])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Evidence retrieved successfully.',
            'data' => $evidence,
        ]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        if ($user->hasRole('admin')) {
            $evidence = SkillEvidence::with([
                'skill',
                'reviewer',
            ])->findOrFail($id);
        } else {
            $studentProfile = $user->studentProfile;

            if (! $studentProfile) {
                return response()->json([
                    'success' => false,
                    'message' => 'You must complete your student profile first.',
                ], 422);
            }

            $evidence = SkillEvidence::with([
                'skill',
                'reviewer',
            ])
                ->where('student_profile_id', $studentProfile->id)
                ->findOrFail($id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Evidence retrieved successfully.',
            'data' => $evidence,
        ]);
    }

    public function review(Request $request, $id)
    {
        $request->validate([
            'verification_status' => [
                'required',
                Rule::in(SkillEvidence::REVIEW_OUTCOMES),
            ],

            'reviewer_notes' => [
                'sometimes',
                'string',
                'max:500',
            ],

            'level' => [
                'sometimes',
                'numeric',
                'min:0',
                'max:5',
            ],
        ]);

        if (! $request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to review evidence.',
            ], 403);
        }

        $evidence = SkillEvidence::findOrFail($id);

        if (! in_array($evidence->verification_status, SkillEvidence::REVIEWABLE_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'code' => 'INVALID_EVIDENCE_TRANSITION',
                'message' => 'Evidence in status "'.$evidence->verification_status
                    .'" cannot be reviewed again. Only pending or revision evidence can be reviewed.',
            ], 422);
        }

        $status = $request->verification_status;

        $evidence->update([
            'verification_status' => $status,
            'reviewer_id' => $request->user()->id,
            'reviewer_notes' => $request->reviewer_notes,
        ]);

        if ($request->filled('level')) {
            $corrected = round((float) $request->input('level'), 2);

            $evidence->update([
                'value' => $corrected,
                'normalized_value' => $corrected,
            ]);
        }

        $this->skillEvaluationService->recalculate(
            $evidence->studentProfile,
            $evidence->skill
        );

        SkillDataChanged::dispatch(
            $evidence->studentProfile,
            'evidence_review'
        );

        return response()->json([
            'success' => true,
            'message' => $this->reviewMessage($status),
            'data' => $evidence,
        ]);
    }

    private function reviewMessage(string $status): string
    {
        return match ($status) {
            SkillEvidence::STATUS_VERIFIED => 'Evidence verified successfully.',
            SkillEvidence::STATUS_REJECTED => 'Evidence rejected successfully.',
            SkillEvidence::STATUS_REVISION => 'Revision requested for the evidence successfully.',
            default => 'Evidence reviewed successfully.',
        };
    }
}
