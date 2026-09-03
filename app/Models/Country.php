<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $fillable = ['name', 'name_ar', 'iso2', 'iso3'];

    public function universities(): HasMany
    {
        return $this->hasMany(University::class);
    }
}
