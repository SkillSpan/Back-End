<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'name', 'description', 'environment'];

    protected $casts = ['is_enabled' => 'boolean'];

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
