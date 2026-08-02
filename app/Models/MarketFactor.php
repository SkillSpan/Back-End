<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketFactor extends Model
{
    use HasFactory;

    protected $fillable = ['career_role_id','skill_id','demand_value','source','geography','segment','collection_date'];

    protected $casts = ['collection_date' => 'date'];

    public function careerRole()
    {
        return $this->belongsTo(CareerRole::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
