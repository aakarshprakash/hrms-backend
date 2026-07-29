<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\OvertimeRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OvertimeRule>
 */
class OvertimeRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'daily_threshold_hours' => 8.00,
            'weekly_threshold_hours' => 48.00,
            'rate_multiplier' => 1.50,
        ];
    }
}
