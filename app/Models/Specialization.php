<?php

namespace App\Models;

use App\Services\CareerRoles\CareerRoleCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Specialization extends Model
{
    protected $fillable = ['name', 'description', 'is_active', 'is_free_track'];

    protected $casts = ['is_active' => 'boolean', 'is_free_track' => 'boolean'];

    public function careerRoles()
    {
        return $this->belongsToMany(CareerRole::class, 'career_role_specialization')->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('specializations.is_active', true);
    }

    public function isFreeTrack(): bool
    {
        return (bool) $this->is_free_track;
    }

    public function selectableCareerRolesQuery(): Builder
    {
        return app(CareerRoleCatalogService::class)->selectableQuery($this);
    }

    public function allowsCareerRole(int $careerRoleId): bool
    {
        return CareerRole::query()
            ->selectable()
            ->whereKey($careerRoleId)
            ->exists();
    }
}
