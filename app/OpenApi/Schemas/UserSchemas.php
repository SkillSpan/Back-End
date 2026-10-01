<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    title: 'User',
    description: 'A platform account (learner, organization member, mentor or administrator). '
        .'The exact field set returned varies per endpoint; only stable fields are listed.',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 42),
        new OA\Property(property: 'name', type: 'string', example: 'Sara Ahmad'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'sara@example.com'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+962790000000'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'active', 'suspended', 'deleted'], example: 'active'),
        new OA\Property(property: 'locale', type: 'string', nullable: true, enum: ['en', 'ar'], example: 'en'),
        new OA\Property(property: 'email_verified_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_login_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(ref: '#/components/schemas/Role')),
        new OA\Property(property: 'student_profile', ref: '#/components/schemas/StudentProfile', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'Role',
    title: 'Role',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 2),
        new OA\Property(property: 'name', type: 'string', example: 'learner'),
        new OA\Property(property: 'slug', type: 'string', nullable: true, example: 'learner'),
    ],
)]
#[OA\Schema(
    schema: 'StudentProfile',
    title: 'StudentProfile',
    description: 'The learner\'s profile as returned by the profile endpoints (the controller\'s `present()` shape).',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 12),
        new OA\Property(property: 'university_name', type: 'string', nullable: true, example: 'University of Jordan'),
        new OA\Property(property: 'student_university_number', type: 'string', nullable: true, example: '20211234'),
        new OA\Property(property: 'specialization', type: 'string', nullable: true, example: 'Computer Science'),
        new OA\Property(property: 'academic_level', type: 'string', nullable: true, example: '3rd year'),
        new OA\Property(property: 'expected_graduation', type: 'integer', nullable: true, example: 2027),
        new OA\Property(property: 'bio', type: 'string', nullable: true),
        new OA\Property(property: 'career_status', type: 'string', nullable: true, example: 'enrolled'),
        new OA\Property(property: 'interests', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'availability', type: 'string', nullable: true),
        new OA\Property(property: 'weekly_availability_hours', type: 'number', format: 'float', nullable: true, example: 20),
        new OA\Property(property: 'preferred_work_type', type: 'string', nullable: true),
        new OA\Property(property: 'visibility', type: 'string', enum: ['public', 'organization_only', 'private'], nullable: true),
        new OA\Property(property: 'completeness_percent', type: 'integer', example: 71),
        new OA\Property(property: 'enrollment_status', type: 'string', nullable: true),
        new OA\Property(property: 'graduation_status', type: 'string', nullable: true),
        new OA\Property(property: 'graduation_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'Organization',
    title: 'Organization',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 7),
        new OA\Property(property: 'name', type: 'string', example: 'Acme Software'),
        new OA\Property(property: 'type', type: 'string', enum: ['company', 'university', 'training_partner'], nullable: true),
        new OA\Property(property: 'verification_status', type: 'string', enum: ['pending', 'approved', 'rejected'], example: 'approved'),
        new OA\Property(property: 'contact_email', type: 'string', format: 'email', nullable: true),
        new OA\Property(property: 'website', type: 'string', nullable: true),
        new OA\Property(property: 'industry', type: 'string', nullable: true),
        new OA\Property(property: 'country', type: 'string', nullable: true),
        new OA\Property(property: 'city', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class UserSchemas {}
