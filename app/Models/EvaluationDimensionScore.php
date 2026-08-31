<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvaluationDimensionScore extends Model
{
    use HasFactory;

    protected $fillable = ['evaluation_id', 'rubric_dimension_id', 'score', 'comment'];

    public function evaluation()
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function dimension()
    {
        return $this->belongsTo(RubricDimension::class, 'rubric_dimension_id');
    }
}
