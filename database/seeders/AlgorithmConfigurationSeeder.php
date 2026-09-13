<?php

namespace Database\Seeders;

use App\Models\AlgorithmConfiguration;
use Illuminate\Database\Seeder;

/**
 * US-INT-01 §10 — seeds the initial active algorithm configuration so
 * intelligence calculations have a versioned, auditable configuration
 * to bind to decisions. Idempotent: safe to run repeatedly.
 */
class AlgorithmConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $name = 'intelligence';

        $existing = AlgorithmConfiguration::query()
            ->where('name', $name)
            ->where('version', 1)
            ->first();

        if ($existing) {
            return;
        }

        AlgorithmConfiguration::create([
            'name' => $name,
            'version' => 1,
            'status' => 'active',
            'config' => [
                'skill_scale' => [0, 5],
                'readiness_scale' => [0, 100],
                'importance_weight_scale' => [0, 1],
                'critical_skill_cap' => [
                    'enabled' => true,
                    'factor' => 0.7,
                ],
                'pending_evidence_confidence_factor' => 0.5,
            ],
            'created_by' => null,
            'activated_at' => now(),
        ]);
    }
}
