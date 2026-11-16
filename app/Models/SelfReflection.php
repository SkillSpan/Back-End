<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SelfReflection extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'user_id', 'answers', 'learning_summary', 'submitted_at'];

    protected $casts = [
        'answers' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
