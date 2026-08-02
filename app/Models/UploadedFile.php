<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UploadedFile extends Model
{
    use HasFactory;

<<<<<<< HEAD
    protected $fillable = ['user_id','type','path','mime_type','size','status','fileable_type','fileable_id'];
=======
    protected $fillable = ['user_id','type','path','mime_type','size','status'];
>>>>>>> a10e558bb789c2f9197fa7aa54cf9d3f4899fde0

    public function user()
    {
        return $this->belongsTo(User::class);
    }
<<<<<<< HEAD
    // العلاقة المتعددة الأشكال: هذا الملف يتبع كياناً آخر (منظمة أو غيره)
    public function fileable()
    {
        return $this->morphTo();
    }
=======
>>>>>>> a10e558bb789c2f9197fa7aa54cf9d3f4899fde0
}
