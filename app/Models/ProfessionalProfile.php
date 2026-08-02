<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfessionalProfile extends Model
{
    use HasFactory;

    protected $fillable = ['user_id','type','expertise','affiliation','availability','verification_status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
