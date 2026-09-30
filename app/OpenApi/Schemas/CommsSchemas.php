<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Connection',
    title: 'Connection',
    description: 'A mentor-student connection (App\Http\Resources\ConnectionResource).',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'mentor_id', type: 'integer'),
        new OA\Property(property: 'student_id', type: 'integer'),
        new OA\Property(property: 'project_id', type: 'integer', nullable: true),
        new OA\Property(property: 'status', type: 'string', nullable: true, example: 'active'),
        new OA\Property(property: 'initiated_by', type: 'string', nullable: true),
        new OA\Property(property: 'disconnected_reason', type: 'string', nullable: true),
        new OA\Property(property: 'disconnected_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'mentor', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'student', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'student_profile', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'project', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'Conversation',
    title: 'Conversation',
    description: 'A mentor-student conversation (App\Http\Resources\ConversationResource).',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'mentor_student_connection_id', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', nullable: true),
        new OA\Property(property: 'created_by', type: 'integer', nullable: true),
        new OA\Property(property: 'last_message_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'retention_expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'connection', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'Message',
    title: 'Message',
    description: 'A conversation message (App\Http\Resources\MessageResource).',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'conversation_id', type: 'integer'),
        new OA\Property(property: 'sender_id', type: 'integer', nullable: true),
        new OA\Property(property: 'sender_name', type: 'string', nullable: true),
        new OA\Property(property: 'body', type: 'string'),
        new OA\Property(property: 'message_type', type: 'string', nullable: true, example: 'human'),
        new OA\Property(property: 'metadata', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'read_by', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'Notification',
    title: 'Notification',
    description: 'An in-app notification (App\Http\Resources\NotificationResource).',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'category', type: 'string', nullable: true),
        new OA\Property(property: 'channel', type: 'string', nullable: true),
        new OA\Property(property: 'title', type: 'string', nullable: true),
        new OA\Property(property: 'body', type: 'string', nullable: true),
        new OA\Property(property: 'link', type: 'string', nullable: true),
        new OA\Property(property: 'event_key', type: 'string', nullable: true),
        new OA\Property(property: 'is_read', type: 'boolean'),
        new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'NotificationPreference',
    title: 'NotificationPreference',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'user_id', type: 'integer'),
        new OA\Property(property: 'category', type: 'string'),
        new OA\Property(property: 'channel', type: 'string'),
        new OA\Property(property: 'enabled', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class CommsSchemas {}
