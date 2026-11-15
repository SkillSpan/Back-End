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

class SupportRequestService
{
    public const CATEGORY = 'support_request';

    public const HANDOFF_NOTICE = 'Transferring you to technical support. A mentor will reply here shortly.';

    public function __construct(private readonly NotificationService $notifications) {}

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

    public function postLearnerMessage(SupportRequest $request, User $learner, string $body): SupportMessage
    {
        $this->assertParticipant($request, $learner);
        $this->assertOpen($request);

        $message = $this->appendMessage($request, $learner, $body);

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

    public function postSupportMessage(SupportRequest $request, User $support, string $body): SupportMessage
    {
        $this->assertOpen($request);

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

    public function assign(SupportRequest $request, User $support): SupportRequest
    {
        $request->forceFill([
            'assigned_to' => $support->id,
            'status' => SupportRequest::STATUS_ASSIGNED,
            'assigned_at' => $request->assigned_at ?? now(),
        ])->save();

        return $request;
    }

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

    private function connectedMentorId(User $learner): ?int
    {
        return MentorStudentConnection::query()
            ->where('student_id', $learner->id)
            ->where('status', 'active')
            ->latest('id')
            ->value('mentor_id');
    }

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

    private function assertParticipant(SupportRequest $request, User $user): void
    {
        if ($request->user_id !== $user->id) {
            throw new ReadinessException(
                'The support request is not available.',
                404,
                'SUPPORT_REQUEST_NOT_FOUND',
            );
        }
    }

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
