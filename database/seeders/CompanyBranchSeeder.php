<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanyBranchSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::firstOrCreate(['slug' => 'acme-corp'], [
            'name' => 'Acme Corp',
            'industry' => 'general',
            'timezone' => 'Asia/Kolkata',
        ]);
        $company->forceFill(['onboarded_at' => $company->onboarded_at ?? now()])->save();

        foreach ([
            ['name' => 'Head Office', 'address' => '123 Main Street', 'city' => 'Mumbai'],
            ['name' => 'Bangalore Branch', 'address' => '456 MG Road', 'city' => 'Bangalore'],
        ] as $branch) {
            Branch::firstOrCreate(
                ['company_id' => $company->id, 'name' => $branch['name']],
                $branch + ['country' => 'India', 'timezone' => 'Asia/Kolkata', 'currency_code' => 'INR', 'week_off_days' => [0]]
            );
        }
    }
}
