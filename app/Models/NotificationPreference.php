<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'category', 'channel', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
