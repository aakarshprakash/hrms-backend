<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanyBranchSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::create([
            'name' => 'Acme Corp',
            'timezone' => 'Asia/Kolkata',
        ]);

        Branch::create([
            'company_id' => $company->id,
            'name' => 'Head Office',
            'address' => '123 Main Street',
            'city' => 'Mumbai',
            'country' => 'India',
            'timezone' => 'Asia/Kolkata',
            'currency_code' => 'INR',
        ]);

        Branch::create([
            'company_id' => $company->id,
            'name' => 'Bangalore Branch',
            'address' => '456 MG Road',
            'city' => 'Bangalore',
            'country' => 'India',
            'timezone' => 'Asia/Kolkata',
            'currency_code' => 'INR',
        ]);
    }
}
