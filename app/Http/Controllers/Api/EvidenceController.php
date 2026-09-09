<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    /**
     * POST /api/v1/evidence
     *
     * Submit new evidence for a skill.
     * Evidence is created as pending until reviewed by an admin.
     */
    public function store(Request $request)
    {
        $request->validate([
            'skill_id' => [
                'required',
                Rule::exists('skills', 'id')->where(function ($query) {
                    $query->where('status', 'active');
                }),
            ],

            'evidence_url' => [
                'sometimes',
                'required_without:evidence_file',
                'url',
            ],

            'evidence_file' => [
                'sometimes',
                'required_without:evidence_url',
                'file',
                'max:10000',
            ],

            'description' => [
                'sometimes',
                'string',
                'max:500',
            ],

            'evidence_date' => [
                'sometimes',
                'date',
            ],
        ]);

        $studentProfile = $request->user()->studentProfile;

        if (! $studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'You must complete your student profile before submitting evidence.',
            ], 422);
        }

        $skillId = $request->skill_id;

        /*
         * Use URL as reference when provided.
         * Otherwise store the uploaded file and use its path as reference.
         */
        if ($request->filled('evidence_url')) {
            $reference = $request->evidence_url;
        } elseif ($request->hasFile('evidence_file')) {
            $reference = $request->file('evidence_file')->store('evidence');
        } else {
            $reference = null;
        }

        /*
         * Prevent duplicate evidence for the same learner,
         * skill, source and reference.
         */
        $duplicate = SkillEvidence::where(
            'student_profile_id',
            $studentProfile->id
        )
            ->where('skill_id', $skillId)
            ->where('source', 'certificate')
            ->where('reference', $reference)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate evidence already exists for this skill.',
            ], 409);
        }

        /*
         * New evidence starts as pending.
         *
         * recency_factor is initially set for storage compatibility,
         * but SkillEvaluationService recalculates it dynamically
         * during every recalculate().
         */
        $evidence = SkillEvidence::create([
            'student_profile_id' => $studentProfile->id,
            'skill_id' => $skillId,
            'source' => 'certificate',
            'value' => 0,
            'normalized_value' => 0,
            'reference' => $reference,

            'evidence_date' => $request->filled('evidence_date')
                ? $request->evidence_date
                : now()->toDateString(),

            'verification_status' => 'pending',
            'recency_factor' => 1.00,
        ]);

        /*
         * Recalculate immediately.
         *
         * Pending evidence contributes to confidence using
         * the configured pending verification factor.
         */
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

    /**
     * GET /api/v1/evidence
     *
     * Retrieve verified evidence for the authenticated learner.
     */
    public function index(Request $request)
    {
        $studentProfile = $request->user()->studentProfile;

        if (! $studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'You must complete your student profile first.',
            ], 422);
        }

        $evidence = SkillEvidence::where(
            'student_profile_id',
            $studentProfile->id
        )
            ->where('verification_status', 'verified')
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

    /**
     * GET /api/v1/evidence/{id}
     *
     * Retrieve a specific evidence record.
     *
     * Only:
     * - Admin
     * - Owner of the evidence
     *
     * can access the record.
     */
    public function show(Request $request, $id)
    {
        $evidence = SkillEvidence::with([
            'skill',
            'reviewer',
        ])->findOrFail($id);

        $user = $request->user();
        $studentProfile = $user->studentProfile;

        $owns = $studentProfile
            && $evidence->student_profile_id === $studentProfile->id;

        if (! $user->hasRole('admin') && ! $owns) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to view this evidence record.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Evidence retrieved successfully.',
            'data' => $evidence,
        ]);
    }

    /**
     * PUT /api/v1/evidence/{id}/review
     *
     * Admin reviews evidence and marks it as:
     * - verified
     * - rejected
     */
    public function review(Request $request, $id)
    {
        $request->validate([
            'verification_status' => [
                'required',
                Rule::in([
                    'verified',
                    'rejected',
                ]),
            ],

            'reviewer_notes' => [
                'sometimes',
                'string',
                'max:500',
            ],
        ]);

        /*
         * Only admins can review evidence.
         */
        if (! $request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to review evidence.',
            ], 403);
        }

        $evidence = SkillEvidence::findOrFail($id);

        /*
         * Update verification information.
         */
        $evidence->update([
            'verification_status' => $request->verification_status,
            'reviewer_id' => $request->user()->id,
            'reviewer_notes' => $request->reviewer_notes,
        ]);

        /*
         * Recalculate skill level and confidence after
         * the evidence verification status changes.
         */
        $this->skillEvaluationService->recalculate(
            $evidence->studentProfile,
            $evidence->skill
        );

        return response()->json([
            'success' => true,
            'message' => 'Evidence '
                . strtolower($request->verification_status)
                . ' successfully.',
            'data' => $evidence,
        ]);
    }
}
