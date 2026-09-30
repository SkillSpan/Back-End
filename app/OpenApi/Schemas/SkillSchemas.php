<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Skill',
    title: 'Skill',
    description: 'A skill from the taxonomy (skills table, with aliases/parent/children/career roles eager-loaded).',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 15),
        new OA\Property(property: 'name', type: 'string', example: 'PHP'),
        new OA\Property(property: 'slug', type: 'string', nullable: true, example: 'php'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'parent_id', type: 'integer', nullable: true),
        new OA\Property(property: 'aliases', type: 'array', nullable: true, items: new OA\Items(type: 'object', additionalProperties: true)),
        new OA\Property(property: 'parent', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'children', type: 'array', nullable: true, items: new OA\Items(type: 'object', additionalProperties: true)),
        new OA\Property(property: 'career_roles', type: 'array', nullable: true, items: new OA\Items(type: 'object', additionalProperties: true)),
    ],
)]
#[OA\Schema(
    schema: 'LearnerSkill',
    title: 'LearnerSkill',
    description: 'A row of the learner skill matrix (`learner_skills`).',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 88),
        new OA\Property(property: 'learner_id', type: 'integer', example: 42),
        new OA\Property(property: 'skill_id', type: 'integer', example: 15),
        new OA\Property(property: 'level', type: 'number', format: 'float', example: 3.5),
        new OA\Property(property: 'confidence_score', type: 'number', format: 'float', example: 72.5),
        new OA\Property(property: 'source_type', type: 'string', nullable: true),
        new OA\Property(property: 'algorithm_version', type: 'string', nullable: true),
        new OA\Property(property: 'configuration_version', type: 'string', nullable: true),
        new OA\Property(property: 'source_contributions', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'calculated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'skill', ref: '#/components/schemas/Skill', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'Evidence',
    title: 'Evidence',
    description: 'A skill evidence record (`skill_evidence`) as returned by the evidence endpoints.',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 301),
        new OA\Property(property: 'student_profile_id', type: 'integer', example: 12),
        new OA\Property(property: 'skill_id', type: 'integer', example: 15),
        new OA\Property(property: 'source', type: 'string', example: 'certificate'),
        new OA\Property(property: 'value', type: 'number', format: 'float', example: 0),
        new OA\Property(property: 'normalized_value', type: 'number', format: 'float', example: 0),
        new OA\Property(property: 'reference', type: 'string', nullable: true, description: 'A URL, or the stored path of the uploaded file.'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'evidence_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'verification_status', type: 'string', enum: ['pending', 'verified', 'rejected'], example: 'pending'),
        new OA\Property(property: 'recency_factor', type: 'number', format: 'float', example: 1.0),
        new OA\Property(property: 'reviewer_id', type: 'integer', nullable: true),
        new OA\Property(property: 'reviewer_notes', type: 'string', nullable: true),
        new OA\Property(property: 'skill', ref: '#/components/schemas/Skill', nullable: true),
        new OA\Property(property: 'reviewer', ref: '#/components/schemas/User', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'CareerRole',
    title: 'CareerRole',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 3),
        new OA\Property(property: 'title', type: 'string', example: 'Backend Developer'),
        new OA\Property(property: 'version', type: 'integer', example: 1),
        new OA\Property(property: 'effective_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'approved', 'retired'], example: 'approved'),
    ],
)]
#[OA\Schema(
    schema: 'CareerRoleSnapshot',
    title: 'CareerRoleSnapshot',
    description: 'The career-role decision snapshot returned by GET /career-roles/{id}/skills.',
    type: 'object',
    properties: [
        new OA\Property(property: 'career_role', ref: '#/components/schemas/CareerRole'),
        new OA\Property(
            property: 'skills',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                properties: [
                    new OA\Property(property: 'skill_id', type: 'integer'),
                    new OA\Property(property: 'skill_name', type: 'string', nullable: true),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'required_level', type: 'number', format: 'float'),
                    new OA\Property(property: 'importance_weight', type: 'number', format: 'float'),
                    new OA\Property(property: 'is_critical', type: 'boolean'),
                    new OA\Property(property: 'prerequisites', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
                ],
            ),
        ),
        new OA\Property(
            property: 'statistics',
            type: 'object',
            properties: [
                new OA\Property(property: 'total_skills', type: 'integer'),
                new OA\Property(property: 'critical_skills_count', type: 'integer'),
                new OA\Property(property: 'average_importance_weight', type: 'number', format: 'float'),
            ],
        ),
        new OA\Property(
            property: 'snapshot',
            type: 'object',
            properties: [
                new OA\Property(property: 'is_valid', type: 'boolean'),
                new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
            ],
        ),
    ],
)]
class SkillSchemas {}
