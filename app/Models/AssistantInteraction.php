<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantInteraction extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const REPORT_UNSAFE = 'unsafe';

    public const REPORT_IRRELEVANT = 'irrelevant';

    public const REPORT_UNFAIR = 'unfair';

    public const REPORT_INCORRECT = 'incorrect';

    protected $fillable = [
        'student_profile_id',
        'intent',
        'context_reference',
        'related_recommendation_id',
        'related_project_id',
        'response_status',
        'answer_status',
        'grounded',
        'report_status',
        'report_reason',
        'reported_at',
        'algorithm_version',
        'prompt_version',
        'configuration_version',
        'failure_code',
        'request_id',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'grounded' => 'boolean',
    ];

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class, 'related_recommendation_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'related_project_id');
    }
}
