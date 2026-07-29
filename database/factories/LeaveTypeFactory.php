<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->randomElement(['Annual Leave', 'Sick Leave', 'Casual Leave', 'Maternity Leave']),
            'days_per_year' => fake()->numberBetween(6, 30),
            'carry_forward' => fake()->boolean(),
            'paid' => true,
        ];
    }
}
