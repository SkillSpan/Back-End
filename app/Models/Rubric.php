<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rubric extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'version', 'created_by'];

    protected $casts = ['published_at' => 'datetime'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dimensions()
    {
        return $this->hasMany(RubricDimension::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}
