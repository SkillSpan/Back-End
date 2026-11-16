<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfessionalRecordShareAccess extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['share_link_id', 'ip_address', 'user_agent', 'accessed_at'];

    protected $casts = ['accessed_at' => 'datetime'];

    public function shareLink()
    {
        return $this->belongsTo(ProfessionalRecordShareLink::class, 'share_link_id');
    }
}
