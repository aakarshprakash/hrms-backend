<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryStructure>
 */
class SalaryStructureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'component_id' => SalaryComponent::factory(),
            'amount' => fake()->randomFloat(2, 5000, 50000),
            'effective_from' => now()->startOfYear()->toDateString(),
            'effective_to' => null,
        ];
    }
}
