<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UploadedFile extends Model
{
    use HasFactory;

    protected $fillable = ['user_id','type','path','mime_type','size','status','fileable_type','fileable_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    // العلاقة المتعددة الأشكال: هذا الملف يتبع كياناً آخر (منظمة أو غيره)
    public function fileable()
    {
        return $this->morphTo();
    }
}
