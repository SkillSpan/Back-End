<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's recorded request to contact a candidate.
 *
 * This is what justifies withholding the student's e-mail from the talent
 * payload: the company asks through a row that both sides can see, instead of
 * harvesting an address from a list endpoint.
 */
class TalentContactRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_DECLINED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'organization_id',
        'student_id',
        'requested_by',
        'message',
        'status',
        'responded_at',
    ];

    protected $casts = ['responded_at' => 'datetime'];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
