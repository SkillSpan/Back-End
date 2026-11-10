<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TalentList extends Model
{
    use HasFactory;

    protected $fillable = ['organization_id', 'created_by', 'name', 'description'];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function candidates()
    {
        return $this->hasMany(TalentListCandidate::class);
    }
}
