<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\SalaryComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryComponent>
 */
class SalaryComponentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->randomElement(['Basic', 'HRA', 'Transport', 'PF', 'Professional Tax']),
            'type' => fake()->randomElement(['earning', 'deduction']),
            'calculation_type' => 'fixed',
        ];
    }

    public function earning(): static
    {
        return $this->state(['type' => 'earning', 'calculation_type' => 'fixed']);
    }

    public function deduction(): static
    {
        return $this->state(['type' => 'deduction', 'calculation_type' => 'fixed']);
    }
}
