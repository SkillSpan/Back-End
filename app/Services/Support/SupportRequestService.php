<?php

namespace App\Services\Support;

use App\Exceptions\ReadinessException;
use App\Models\AssistantInteraction;
use App\Models\MentorStudentConnection;
use App\Models\StudentProfile;
use App\Models\SupportMessage;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * US-REC-01 follow-up — escalating an assistant conversation to a human.
 *
 * The assistant answers `insufficient_context` when it has nothing in its
 * documentation to ground an answer in. The learner is then offered a person,
 * and this service is what turns that offer into a tracked request.
 *
 * Two things this deliberately does NOT do:
 *
 *   1. It does not touch `conversations`. That table requires a
 *      `mentor_student_connection_id`, so a learner with no mentor could never
 *      be escalated — the case that most needs help. Support is its own thread.
 *   2. It does not read the learner's chat history from anywhere. The transcript
 *      arrives in the request and is snapshotted here, because the platform
 *      stores no assistant conversation (§12.5 data minimisation). This table is
 *      therefore the ONLY place learner-authored text lives, and it is capped
 *      for exactly that reason.
 */
class SupportRequestService
{
    /** Notification category. One string, so preferences can key off it. */
    public const CATEGORY = 'support_request';

    /** Shown in the thread as the handoff marker. Not attributed to a person. */
    public const HANDOFF_NOTICE = 'Transferring you to technical support. A mentor will reply here shortly.';

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Escalate an assistant conversation to a human.
     *
     * Idempotent per learner: if an open request already exists it is returned
     * untouched. A learner who clicks the button twice, or reloads mid-transfer,
     * must not create a second thread — support would see duplicates and the
     * first reply would land on a request nobody is watching.
     *
     * @param  array{reason?: string, subject?: string, transcript?: array<int, array{role?: string, body?: string}>}  $input
     *
     * @throws ReadinessException
     */
    public function requestHandoff(
        User $learner,
        array $input,
        ?StudentProfile $profile = null,
        ?AssistantInteraction $interaction = null,
        ?string $requestId = null,
    ): SupportRequest {
        $existing = SupportRequest::query()
            ->where('user_id', $learner->id)
            ->open()
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $transcript = $this->snapshotTranscript($input['transcript'] ?? []);
        $reason = $this->normaliseReason($input['reason'] ?? null);

        // The mentor already connected to this learner is the natural support
        // person. Without one the request stays unclaimed for an administrator
        // to pick up — see the admin support inbox.
        $mentorId = $this->connectedMentorId($learner);

        $request = DB::transaction(function () use ($learner, $profile, $interaction, $transcript, $reason, $mentorId, $input) {
            $supportRequest = SupportRequest::create([
                'user_id' => $learner->id,
                'student_profile_id' => $profile?->id,
                'assigned_to' => $mentorId,
                'status' => $mentorId !== null
                    ? SupportRequest::STATUS_ASSIGNED
                    : SupportRequest::STATUS_PENDING,
                'reason' => $reason,
                'subject' => $this->deriveSubject($input['subject'] ?? null, $transcript),
                'transcript' => $transcript,
                'source_interaction_id' => $interaction?->id,
                'assigned_at' => $mentorId !== null ? now() : null,
                'last_message_at' => now(),
            ]);

            // A visible marker so the learner's thread shows what happened
            // without pretending a person wrote it.
            SupportMessage::create([
                'support_request_id' => $supportRequest->id,
                'sender_id' => $learner->id,
                'body' => self::HANDOFF_NOTICE,
                'message_type' => SupportMessage::TYPE_SYSTEM,
            ]);

            return $supportRequest;
        });

        $this->notifySupport($request, $mentorId, $learner);

        Log::info('Support handoff requested.', [
            'request_id' => $requestId,
            'support_request_id' => $request->id,
            'learner_id' => $learner->id,
            'mentor_id' => $mentorId,
            'reason' => $request->reason,
            'transcript_turns' => count($transcript),
        ]);

        return $request;
    }

    /**
     * A message from the learner in an open support thread.
     *
     * @throws ReadinessException
     */
    public function postLearnerMessage(SupportRequest $request, User $learner, string $body): SupportMessage
    {
        $this->assertParticipant($request, $learner);
        $this->assertOpen($request);

        $message = $this->appendMessage($request, $learner, $body);

        // Tell whoever owns it. An unclaimed request has nobody to notify —
        // administrators were already told when it was created.
        if ($request->assigned_to !== null) {
            $this->notifications->dispatch(
                userId: $request->assigned_to,
                category: self::CATEGORY,
                title: 'New reply in support request #'.$request->id,
                body: Str::limit($body, 100),
                link: '/admin/support',
                eventKey: 'support_message:'.$message->id,
            );
        }

        return $message;
    }

    /**
     * A reply from a support person (mentor or administrator).
     *
     * @throws ReadinessException
     */
    public function postSupportMessage(SupportRequest $request, User $support, string $body): SupportMessage
    {
        $this->assertOpen($request);

        // First reply claims an unclaimed request. Doing it here rather than in
        // a separate "assign" click means a request can never be answered by
        // someone who is not recorded as its owner.
        if ($request->assigned_to === null) {
            $this->assign($request, $support);
            $request->refresh();
        }

        $message = $this->appendMessage($request, $support, $body);

        $this->notifications->dispatch(
            userId: $request->user_id,
            category: self::CATEGORY,
            title: 'Support replied to your request',
            body: Str::limit($body, 100),
            link: '/support/requests/'.$request->id,
            eventKey: 'support_message:'.$message->id,
        );

        return $message;
    }

    /** Claim an unclaimed request for a support person. */
    public function assign(SupportRequest $request, User $support): SupportRequest
    {
        $request->forceFill([
            'assigned_to' => $support->id,
            'status' => SupportRequest::STATUS_ASSIGNED,
            'assigned_at' => $request->assigned_at ?? now(),
        ])->save();

        return $request;
    }

    /** Close a request as answered. */
    public function resolve(SupportRequest $request, User $support): SupportRequest
    {
        $request->forceFill([
            'status' => SupportRequest::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();

        $this->notifications->dispatch(
            userId: $request->user_id,
            category: self::CATEGORY,
            title: 'Your support request was resolved',
            body: 'Request #'.$request->id.' has been marked as resolved.',
            link: '/support/requests/'.$request->id,
            eventKey: 'support_resolved:'.$request->id,
        );

        return $request;
    }

    /**
     * The support person already connected to this learner, if any.
     *
     * Only an `active` connection counts. A `pending` one is a request the
     * learner has not accepted, and routing their question to someone they have
     * not agreed to work with would be a privacy problem, not a convenience.
     */
    private function connectedMentorId(User $learner): ?int
    {
        return MentorStudentConnection::query()
            ->where('student_id', $learner->id)
            ->where('status', 'active')
            ->latest('id')
            ->value('mentor_id');
    }

    /**
     * Notify the assigned mentor, and administrators for visibility.
     *
     * The mentor is the person who will answer; administrators are told so an
     * unclaimed request does not sit unnoticed.
     */
    private function notifySupport(SupportRequest $request, ?int $mentorId, User $learner): void
    {
        $body = $learner->name.' asked for help: '.($request->subject ?? 'assistant could not answer');

        if ($mentorId !== null) {
            $this->notifications->dispatch(
                userId: $mentorId,
                category: self::CATEGORY,
                title: 'A learner needs support',
                body: Str::limit($body, 100),
                link: '/admin/support',
                eventKey: 'support_request:'.$request->id,
            );
        }

        if (! config('services.support.notify_admins', true)) {
            return;
        }

        $adminIds = User::query()
            ->whereHas('roles', fn ($query) => $query->where('slug', 'admin'))
            ->pluck('id');

        foreach ($adminIds as $adminId) {
            // The mentor is usually also not an admin, but skip a duplicate
            // notification if the same person would receive both.
            if ($mentorId !== null && (int) $adminId === $mentorId) {
                continue;
            }

            $this->notifications->dispatch(
                userId: (int) $adminId,
                category: self::CATEGORY,
                title: $mentorId !== null
                    ? 'Support request assigned'
                    : 'Support request needs an owner',
                body: Str::limit($body, 100),
                link: '/admin/support',
                eventKey: 'support_request:'.$request->id,
            );
        }
    }

    /**
     * Keep the most recent N turns and drop anything malformed.
     *
     * The learner's own words are the only content stored here, so the cap is
     * not just a size guard — it bounds how much of a private conversation is
     * retained. Oldest turns are dropped first: support needs the question that
     * failed, not the whole session that led to it.
     *
     * @param  array<int, mixed>  $turns
     * @return array<int, array{role: string, body: string}>
     */
    private function snapshotTranscript(array $turns): array
    {
        $limit = max(1, (int) config('services.support.max_transcript_messages', 20));

        $clean = [];

        foreach ($turns as $turn) {
            if (! is_array($turn)) {
                continue;
            }

            $body = isset($turn['body']) && is_string($turn['body'])
                ? trim($turn['body'])
                : '';

            if ($body === '') {
                continue;
            }

            $role = ($turn['role'] ?? null) === 'assistant' ? 'assistant' : 'learner';

            $clean[] = [
                'role' => $role,
                'body' => Str::limit($body, 2000, ''),
            ];
        }

        return array_slice($clean, -$limit);
    }

    /** A short line for the support inbox list. */
    private function deriveSubject(?string $subject, array $transcript): ?string
    {
        if (is_string($subject) && trim($subject) !== '') {
            return Str::limit(trim($subject), 180, '');
        }

        foreach ($transcript as $turn) {
            if ($turn['role'] === 'learner') {
                return Str::limit($turn['body'], 180, '');
            }
        }

        return null;
    }

    private function normaliseReason(?string $reason): string
    {
        $allowed = [
            SupportRequest::REASON_INSUFFICIENT_CONTEXT,
            SupportRequest::REASON_LEARNER_REQUESTED,
        ];

        return in_array($reason, $allowed, true)
            ? $reason
            : SupportRequest::REASON_INSUFFICIENT_CONTEXT;
    }

    private function appendMessage(SupportRequest $request, User $sender, string $body): SupportMessage
    {
        return DB::transaction(function () use ($request, $sender, $body) {
            $message = SupportMessage::create([
                'support_request_id' => $request->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'message_type' => SupportMessage::TYPE_TEXT,
            ]);

            $request->forceFill(['last_message_at' => now()])->save();

            return $message;
        });
    }

    /** @throws ReadinessException */
    private function assertParticipant(SupportRequest $request, User $user): void
    {
        if ($request->user_id !== $user->id) {
            // Same wording as "does not exist" on purpose: a learner must not be
            // able to probe which request ids belong to other people.
            throw new ReadinessException(
                'The support request is not available.',
                404,
                'SUPPORT_REQUEST_NOT_FOUND',
            );
        }
    }

    /** @throws ReadinessException */
    private function assertOpen(SupportRequest $request): void
    {
        if (! $request->isOpen()) {
            throw new ReadinessException(
                'This support request is already closed.',
                422,
                'SUPPORT_REQUEST_CLOSED',
            );
        }
    }
}
