<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvaluationDispute extends Model
{
    use HasFactory;

    protected $fillable = ['evaluation_id','submitted_by','grounds','status','resolution_notes','resolved_by','resolved_at'];

    protected $casts = ['resolved_at' => 'datetime'];

    public function evaluation()
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
