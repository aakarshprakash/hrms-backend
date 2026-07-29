<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->city() . ' Branch',
            'address' => fake()->address(),
            'city' => fake()->city(),
            'country' => fake()->country(),
            'timezone' => 'UTC',
            'currency_code' => 'USD',
        ];
    }
}
