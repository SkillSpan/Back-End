<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A named shortlist owned by a company.
 *
 * Belongs to the ORGANIZATION, not to the user who created it: two recruiters at
 * the same company share their lists, and a list must survive its creator
 * leaving. `created_by` is attribution, not ownership — which is why every
 * query in TalentEngagementService scopes on `organization_id`.
 */
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
