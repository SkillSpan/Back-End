<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UploadedFile extends Model
{
    use HasFactory;

    protected $fillable = ['user_id','fileable_type','fileable_id','type','path','mime_type','size','status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function fileable()
    {
        return $this->morphTo();
    }
}
