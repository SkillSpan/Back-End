<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RubricDimension extends Model
{
    use HasFactory;

    protected $fillable = [
        'rubric_id',
        'name',
        'weight',
        'score_min',
        'score_max',
        'indicators',
        'order_index',
        'skill_id',
    ];

    protected $casts = [
        'weight' => 'float',
        'score_min' => 'float',
        'score_max' => 'float',
        'indicators' => 'array',
        'order_index' => 'integer',
    ];

    public function rubric()
    {
        return $this->belongsTo(Rubric::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'rubric_dimension_skills')->withTimestamps();
    }

    public function skillIds(): array
    {
        $ids = $this->skills()->pluck('skills.id')->map(fn ($id) => (int) $id)->all();

        if ($ids === [] && $this->skill_id !== null) {
            $ids = [(int) $this->skill_id];
        }

        return $ids;
    }

    public function minimumScore(): float
    {
        return $this->score_min ?? 0.0;
    }

    public function maximumScore(): float
    {
        return $this->score_max ?? 100.0;
    }
}
