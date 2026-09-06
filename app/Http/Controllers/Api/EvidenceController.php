<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SkillEvidence;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EvidenceController extends Controller
{
    /**
     * POST /api/v1/skills/evidence
     * Submit new evidence for a skill.
     */
    public function store(Request $request)
    {
        $request->validate([
            'learner_id' => 'required|exists:users,id',
            'skill_id' => ['required', 'exists:skills,id',
                Rule::where(function ($query) {
                    $query->where('status', 'active');
                }),
            ],
            'evidence_url' => 'sometimes|required_without:evidence_file|url',
            'evidence_file' => 'sometimes|required_without:evidence_file|file|max:10000',
            'description' => 'sometimes|string|max:500',
            'evidence_date' => 'sometimes|date',
        ]);

        $learnerId = $request->learner_id;
        $skillId = $request->skill_id;

        // Validate learner ownership (must match authenticated user)
        $authUserId = auth()->id();
        if ($authUserId && $learnerId !== $authUserId) {
            return response()->json([
                'success' => false,
                'message' => 'You can only submit evidence for your own account.',
            ], 403);
        }

        // Check for duplicate evidence (same URL or same file path)
        $duplicate = SkillEvidence::where('learner_id', $learnerId)
            ->where('skill_id', $skillId)
            ->when($request->filled('evidence_url'), function ($query) use ($request) {
                $query->where('evidence_url', $request->evidence_url);
            })
            ->when($request->filled('evidence_file'), function ($query) use ($request) {
                $query->where('evidence_file', $request->evidence_file);
            })
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate evidence already exists for this skill.',
            ], 409);
        }

        $evidence = SkillEvidence::create([
            'learner_id' => $learnerId,
            'skill_id' => $skillId,
            'evidence_url' => $request->filled('evidence_url') ? $request->evidence_url : null,
            'evidence_file' => $request->filled('evidence_file') ? $request->file('evidence_file')->store('evidence') : null,
            'description' => $request->filled('description') ? $request->description : null,
            'evidence_date' => $request->filled('evidence_date') ? $request->evidence_date : now()->toDateString(),
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Evidence submitted successfully, pending review.',
            'data' => $evidence,
        ], 201);
    }

    /**
     * GET /api/v1/skills/evidence
     * Retrieve evidence for a learner.
     */
    public function index(Request $request)
    {
        $learnerId = $request->query('learner_id');

        if ($learnerId) {
            $evidence = SkillEvidence::where('learner_id', $learnerId)
                ->where('status', 'approved')
                ->with(['skill', 'reviewer'])
                ->get();
        } else {
            $evidence = SkillEvidence::where('status', 'approved')
                ->with(['skill', 'reviewer'])
                ->get();
        }

        return response()->json([
            'success' => true,
            'message' => 'Evidence retrieved successfully.',
            'data' => $evidence,
        ]);
    }

    /**
     * GET /api/v1/skills/evidence/{id}
     * Retrieve specific evidence record.
     */
    public function show($id)
    {
        $evidence = SkillEvidence::with(['skill', 'reviewer'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Evidence retrieved successfully.',
            'data' => $evidence,
        ]);
    }

    /**
     * PUT /api/v1/skills/evidence/{id}/review
     * Review and approve/reject evidence.
     */
    public function review(Request $request, $id)
    {
        $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'review_notes' => 'sometimes|string|max:500',
        ]);

        $evidence = SkillEvidence::findOrFail($id);

        // Validate reviewer authorization (admin only)
        if (auth()->user()->role !== 'admin' && auth()->user()->role !== 'company_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to review evidence.',
            ], 403);
        }

        $evidence->update([
            'status' => $request->status,
            'reviewer_id' => auth()->id(),
            'review_notes' => $request->review_notes,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Evidence ' . strtolower($request->status) . ' successfully.',
            'data' => $evidence,
        ]);
    }
}