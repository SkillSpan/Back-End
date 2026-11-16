<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectDelegate extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'user_id', 'approved_by', 'approved_at', 'revoked_at'];

    protected $casts = [
        'approved_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
