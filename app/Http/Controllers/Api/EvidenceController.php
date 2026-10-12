<?php

namespace App\Http\Controllers\Api;

use App\Events\SkillDataChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListEvidenceRequest;
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
                /*
                 * Restrict uploads to the document/image types the evidence
                 * flow actually accepts (certificates and proof photos).
                 *
                 * Without this the endpoint accepted ANY file — including
                 * .php/.phtml, executables, shell scripts and SVG (which can
                 * carry inline script). The file is written to the private
                 * `local` disk, so it is not directly web-served, but storing
                 * an arbitrary attacker-controlled file is a liability the
                 * moment the disk (or a future download route) changes.
                 *
                 * `mimes` validates the type guessed from the file's actual
                 * content, not the client-supplied extension, so a renamed
                 * .php/.svg is rejected as well.
                 */
                'mimes:pdf,jpg,jpeg,png,webp',
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

            /*
             * The learner's own description of the evidence. Validated above,
             * and stored here — it used to be validated and then dropped on
             * the floor, so a client that sent one got a 201 with nothing
             * recorded and no error explaining it.
             */
            'description' => $request->input('description'),

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
     * Retrieve the authenticated learner's own evidence.
     *
     * The list covers EVERY verification state — pending, verified, rejected,
     * expired and revision — not just `verified`. Restricting it to
     * `verified` hid the two states a learner most needs to act on: a
     * reviewer's `revision` request ("clarify/replace this") and a `rejected`
     * decision were invisible, so the revision workflow had no way to reach
     * the learner it was created for (SRS SKL-09 requires the evidence and
     * its state to be viewable).
     *
     * Optional `?status={state}` narrows the list to a single state; the
     * value is validated against SkillEvidence::STATUSES by
     * ListEvidenceRequest. Omitting it returns everything.
     *
     * Ownership is enforced in the query itself — the learner can only ever
     * see rows on their own student profile.
     */
    public function index(ListEvidenceRequest $request)
    {
        $studentProfile = $request->user()->studentProfile;

        if (! $studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'You must complete your student profile first.',
            ], 422);
        }

        // Present-and-valid, or null when the filter was omitted.
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
        $user = $request->user();

        /*
         * Admins can access any evidence record.
         */
        if ($user->hasRole('admin')) {
            $evidence = SkillEvidence::with([
                'skill',
                'reviewer',
            ])->findOrFail($id);
        } else {
            /*
             * Non-admin users can only access evidence
             * belonging to their own student profile.
             *
             * The ownership check is now part of the query,
             * so we do not retrieve another user's evidence
             * before checking authorization.
             */
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

    /**
     * PUT /api/v1/evidence/{id}/review
     *
     * Admin reviews evidence and marks it as:
     * - verified  → approved
     * - rejected  → denied
     * - revision  → clarification requested (SRS SKL-05 / UC-03)
     *
     * Only evidence that is still awaiting a decision (`pending` or
     * `revision`) may be reviewed. Terminal states are never silently
     * rewritten — SRS BR-EVA-06 requires a correction to supersede or revise,
     * not to overwrite history.
     */
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
         * Guard the transition.
         *
         * A review is a decision on evidence that is still waiting for one.
         * `verified`, `rejected` and `expired` are terminal for this endpoint,
         * so re-reviewing them is refused with an explicit error rather than
         * silently flipping the status.
         */
        if (! in_array($evidence->verification_status, SkillEvidence::REVIEWABLE_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'code' => 'INVALID_EVIDENCE_TRANSITION',
                'message' => 'Evidence in status "'.$evidence->verification_status
                    .'" cannot be reviewed again. Only pending or revision evidence can be reviewed.',
            ], 422);
        }

        $status = $request->verification_status;

        /*
         * Update verification information.
         */
        $evidence->update([
            'verification_status' => $status,
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

        /*
         * US-INT-01:
         * Any evidence review can change the learner's effective
         * skill levels, so it triggers a queued intelligence recalculation
         * for this learner.
         *
         * The synchronous skill recalculation above already ran.
         * This event only enqueues the decision refresh.
         */
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

    /**
     * Human-readable confirmation for a review outcome.
     *
     * Kept as an explicit map rather than the previous
     * "Evidence {$status} successfully" string, because that produced the
     * ungrammatical "Evidence revision successfully." for the new state.
     */
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
