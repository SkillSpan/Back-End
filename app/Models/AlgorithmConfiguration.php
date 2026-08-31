<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlgorithmConfiguration extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'version', 'config', 'created_by'];

    protected $casts = ['config' => 'array', 'activated_at' => 'datetime'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
