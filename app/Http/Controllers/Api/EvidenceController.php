<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SkillEvidence;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EvidenceController extends Controller
{
    /**
     * POST /api/v1/evidence
     * Submit new evidence for a skill (self-submitted certificate/link,
     * pending admin review). Stored in the same skill_evidences table
     * used by the baseline/assessment pipeline, source = 'certificate'.
     */
    public function store(Request $request)
    {
        $request->validate([
            'skill_id' => [
                'required',
                'exists:skills,id',
                Rule::where(function ($query) {
                    $query->where('status', 'active');
                }),
            ],
            'evidence_url' => 'sometimes|required_without:evidence_file|url',
            'evidence_file' => 'sometimes|required_without:evidence_url|file|max:10000',
            'description' => 'sometimes|string|max:500',
            'evidence_date' => 'sometimes|date',
        ]);

        $studentProfile = $request->user()->studentProfile;

        if (! $studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'You must complete your student profile before submitting evidence.',
            ], 422);
        }

        $skillId = $request->skill_id;

        $reference = $request->filled('evidence_url')
            ? $request->evidence_url
            : ($request->hasFile('evidence_file')
                ? $request->file('evidence_file')->store('evidence')
                : null);

        // Check for duplicate evidence (same student, skill, source, reference —
        // matches SKL-07 duplicate-detection requirement).
        $duplicate = SkillEvidence::where('student_profile_id', $studentProfile->id)
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

        $evidence = SkillEvidence::create([
            'student_profile_id' => $studentProfile->id,
            'skill_id' => $skillId,
            'source' => 'certificate',
            'value' => 0,
            'normalized_value' => 0,
            'reference' => $reference,
            'evidence_date' => $request->filled('evidence_date') ? $request->evidence_date : now()->toDateString(),
            'verification_status' => 'pending',
            'recency_factor' => 1.00,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Evidence submitted successfully, pending review.',
            'data' => $evidence,
        ], 201);
    }

    /**
     * GET /api/v1/evidence
     * Retrieve evidence for the authenticated learner.
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

        $evidence = SkillEvidence::where('student_profile_id', $studentProfile->id)
            ->where('verification_status', 'verified')
            ->with(['skill', 'reviewer'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Evidence retrieved successfully.',
            'data' => $evidence,
        ]);
    }

    /**
     * GET /api/v1/evidence/{id}
     * Retrieve a specific evidence record.
     *
     * FIX: this previously had no ownership or role check at all — any
     * authenticated user could fetch any evidence record by id. Now only
     * an admin, or the learner who owns the record (via their own
     * student_profile_id), may retrieve it.
     */
    public function show(Request $request, $id)
    {
        $evidence = SkillEvidence::with(['skill', 'reviewer'])->findOrFail($id);

        $user = $request->user();
        $studentProfile = $user->studentProfile;

        $owns = $studentProfile && $evidence->student_profile_id === $studentProfile->id;

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
     * Review and verify/reject evidence (admin only).
     */
    public function review(Request $request, $id)
    {
        $request->validate([
            'verification_status' => ['required', Rule::in(['verified', 'rejected'])],
            'reviewer_notes' => 'sometimes|string|max:500',
        ]);

        if (! $request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to review evidence.',
            ], 403);
        }

        $evidence = SkillEvidence::findOrFail($id);

        $evidence->update([
            'verification_status' => $request->verification_status,
            'reviewer_id' => $request->user()->id,
            'reviewer_notes' => $request->reviewer_notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Evidence ' . strtolower($request->verification_status) . ' successfully.',
            'data' => $evidence,
        ]);
    }
}
