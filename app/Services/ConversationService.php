<?php

namespace App\Services;

use App\Exceptions\CommunicationException;
use App\Models\Conversation;
use App\Models\MentorStudentConnection;
use App\Models\Message;
use App\Models\User;
use App\Traits\AuditsActions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConversationService
{
    use AuditsActions;

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Create a conversation for a mentor-student connection.
     * Idempotent: if an active conversation already exists, returns it.
     */
    public function createConversation(int $connectionId, int $userId, ?Request $request = null): Conversation
    {
        $connection = MentorStudentConnection::findOrFail($connectionId);

        if (! in_array($userId, [$connection->mentor_id, $connection->student_id])) {
            throw new CommunicationException(
                'You are not a participant in this connection.',
                403,
                'NOT_A_PARTICIPANT',
            );
        }

        if (! in_array($connection->status, ['pending', 'active'])) {
            throw new CommunicationException(
                'This connection is no longer active.',
                422,
                'CONNECTION_NOT_ACTIVE',
            );
        }

        $existing = Conversation::where('mentor_student_connection_id', $connectionId)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            return $existing;
        }

        $retentionDays = (int) config('communication.retention_days', 365);

        $conversation = Conversation::create([
            'mentor_student_connection_id' => $connectionId,
            'status' => 'active',
            'created_by' => $userId,
            'retention_expires_at' => now()->addDays($retentionDays),
        ]);

        $requestId = $request
            ? (string) ($request->header('X-Request-ID') ?: Str::uuid())
            : Str::uuid()->toString();

        $this->audit(
            actorId: $userId,
            action: 'conversation.created',
            entityType: 'Conversation',
            entityId: $conversation->id,
            after: $conversation->toArray(),
            purpose: 'Conversation created for mentor-student connection',
            requestId: $requestId,
            ipAddress: $request?->ip(),
            userAgent: $request?->userAgent(),
        );

        return $conversation;
    }

    /**
     * Get conversations for a user (mentor or student).
     */
    public function getConversations(int $userId)
    {
        $perPage = (int) config('communication.conversations_per_page', 20);

        return Conversation::whereHas('connection', function ($q) use ($userId) {
            $q->where('mentor_id', $userId)->orWhere('student_id', $userId);
        })
            ->where('status', 'active')
            ->with(['connection.mentor', 'connection.student'])
            ->latest('last_message_at')
            ->paginate($perPage);
    }

    /**
     * Get a conversation with authorization check.
     */
    public function getConversation(int $conversationId, int $userId): Conversation
    {
        $conversation = Conversation::with('connection')->findOrFail($conversationId);

        if (! $this->isParticipant($conversation, $userId)) {
            throw new CommunicationException(
                'You are not authorized to access this conversation.',
                403,
                'UNAUTHORIZED_CONVERSATION',
            );
        }

        return $conversation;
    }

    /**
     * Send a message in a conversation.
     */
    public function sendMessage(
        int $conversationId,
        int $senderId,
        string $body,
        string $type = 'text',
        ?array $metadata = null,
        ?Request $request = null,
    ): Message {
        $conversation = $this->getConversation($conversationId, $senderId);

        if ($conversation->status !== 'active') {
            throw new CommunicationException(
                'This conversation is no longer active.',
                422,
                'CONVERSATION_NOT_ACTIVE',
            );
        }

        $maxLength = (int) config('communication.message_max_length', 5000);
        if (strlen($body) > $maxLength) {
            throw new CommunicationException(
                "Message exceeds maximum length of {$maxLength} characters.",
                422,
                'MESSAGE_TOO_LONG',
            );
        }

        $requestId = $request
            ? (string) ($request->header('X-Request-ID') ?: Str::uuid())
            : Str::uuid()->toString();

        DB::beginTransaction();
        try {
            $message = Message::create([
                'conversation_id' => $conversationId,
                'sender_id' => $senderId,
                'body' => $body,
                'message_type' => $type,
                'metadata' => $metadata,
            ]);

            $conversation->update(['last_message_at' => now()]);

            $this->audit(
                actorId: $senderId,
                action: 'message.sent',
                entityType: 'Message',
                entityId: $message->id,
                after: [
                    'conversation_id' => $conversationId,
                    'body_length' => strlen($body),
                    'type' => $type,
                ],
                purpose: 'Message sent in conversation',
                requestId: $requestId,
                ipAddress: $request?->ip(),
                userAgent: $request?->userAgent(),
            );

            // In-app notification to the other participant, honouring
            // their per-category notification preferences.
            $connection = $conversation->connection;
            $recipientId = $senderId === $connection->mentor_id
                ? $connection->student_id
                : $connection->mentor_id;

            $sender = User::find($senderId);

            $this->notifications->dispatch(
                userId: $recipientId,
                category: 'message',
                title: 'New message from '.$sender?->name,
                body: Str::limit($body, 100),
                link: '/conversations/'.$conversationId,
                eventKey: 'message:'.$message->id,
            );

            DB::commit();

            return $message->fresh();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Post a chatbot message into a conversation. Same authorization and
     * state rules as sendMessage, but the message type is forced to
     * 'chatbot' and the audit action is distinct so automated traffic is
     * separable from human messages in the audit trail.
     */
    public function sendChatbotMessage(
        int $conversationId,
        int $actorId,
        string $body,
        array $metadata = [],
        ?Request $request = null,
    ): Message {
        $conversation = $this->getConversation($conversationId, $actorId);

        if ($conversation->status !== 'active') {
            throw new CommunicationException(
                'This conversation is no longer active.',
                422,
                'CONVERSATION_NOT_ACTIVE',
            );
        }

        $maxLength = (int) config('communication.message_max_length', 5000);
        if (strlen($body) > $maxLength) {
            throw new CommunicationException(
                "Message exceeds maximum length of {$maxLength} characters.",
                422,
                'MESSAGE_TOO_LONG',
            );
        }

        $requestId = $request
            ? (string) ($request->header('X-Request-ID') ?: Str::uuid())
            : Str::uuid()->toString();

        DB::beginTransaction();
        try {
            $message = Message::create([
                'conversation_id' => $conversationId,
                'sender_id' => $actorId,
                'body' => $body,
                'message_type' => 'chatbot',
                'metadata' => array_merge(
                    ['source' => 'chatbot'],
                    $metadata,
                ),
            ]);

            $conversation->update(['last_message_at' => now()]);

            $this->audit(
                actorId: $actorId,
                action: 'chatbot.message.sent',
                entityType: 'Message',
                entityId: $message->id,
                after: [
                    'conversation_id' => $conversationId,
                    'body_length' => strlen($body),
                    'type' => 'chatbot',
                    'source' => $metadata['source'] ?? 'chatbot',
                ],
                purpose: 'Chatbot message sent in conversation',
                requestId: $requestId,
                ipAddress: $request?->ip(),
                userAgent: $request?->userAgent(),
            );

            // Notify the other participant, honouring their preferences.
            $connection = $conversation->connection;
            $recipientId = $actorId === $connection->mentor_id
                ? $connection->student_id
                : $connection->mentor_id;

            $this->notifications->dispatch(
                userId: $recipientId,
                category: 'message',
                title: 'New message from Assistant',
                body: Str::limit($body, 100),
                link: '/conversations/'.$conversationId,
                eventKey: 'message:'.$message->id,
            );

            DB::commit();

            return $message->fresh();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * List only the chatbot messages in a conversation (participants only).
     */
    public function getChatbotMessages(int $conversationId, int $userId, int $perPage = 50)
    {
        $conversation = $this->getConversation($conversationId, $userId);

        $perPage = (int) config('communication.messages_per_page', 50);

        return $conversation->messages()
            ->where('message_type', 'chatbot')
            ->latest('created_at')
            ->paginate($perPage);
    }

    /**
     * Get messages for a conversation (paginated, latest first).
     */
    public function getMessages(int $conversationId, int $userId, int $perPage = 50)
    {
        $conversation = $this->getConversation($conversationId, $userId);

        $perPage = (int) config('communication.messages_per_page', 50);

        return $conversation->messages()
            ->latest('created_at')
            ->paginate($perPage);
    }

    /**
     * Mark unread messages as read.
     */
    public function markAsRead(int $conversationId, int $userId): int
    {
        $this->getConversation($conversationId, $userId);

        return Message::where('conversation_id', $conversationId)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'read_by' => $userId,
            ]);
    }

    /**
     * Get communication status for a conversation.
     */
    public function getCommunicationStatus(int $conversationId, int $userId): array
    {
        $conversation = $this->getConversation($conversationId, $userId);

        $unreadCount = Message::where('conversation_id', $conversationId)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->count();

        $lastMessage = Message::where('conversation_id', $conversationId)
            ->latest('created_at')
            ->first();

        return [
            'conversation_id' => $conversation->id,
            'status' => $conversation->status,
            'unread_count' => $unreadCount,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_message_preview' => $lastMessage ? Str::limit($lastMessage->body, 100) : null,
            'retention_expires_at' => $conversation->retention_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Check if a user is a participant in a conversation's connection.
     */
    public function isParticipant(Conversation $conversation, int $userId): bool
    {
        $connection = $conversation->connection;

        if (! $connection) {
            return false;
        }

        return in_array($userId, [$connection->mentor_id, $connection->student_id]);
    }
}
