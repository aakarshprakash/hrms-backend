<?php

/*
|--------------------------------------------------------------------------
| Indian statutory defaults
|--------------------------------------------------------------------------
|
| Starting values for each branch's statutory rules (statutory_rules
| config_json). They are copied into the tenant's own editable rules --
| nothing here is applied directly -- so a rate change or a state not
| listed is handled by editing the rule, not by a code release.
|
| Verify against the current notifications before relying on them: rates
| and slabs change (the income-tax figures below are the Budget 2025 new
| regime, applicable for FY 2025-26 onwards unless amended).
|
*/

return [

    'pf' => [
        'wage_ceiling' => 15000,        // statutory wage ceiling
        'restrict_to_ceiling' => true,  // contribute on min(PF wage, ceiling)
        'employee_rate' => 12,
        'employer_rate' => 12,          // split into EPS + EPF below
        'eps_rate' => 8.33,             // on min(wage, 15000), max ₹1,250
        'edli_rate' => 0.5,             // employer, on min(wage, 15000)
        'admin_rate' => 0.5,            // employer admin charges
    ],

    'esi' => [
        'wage_ceiling' => 21000,        // eligibility: gross wage up to this
        'employee_rate' => 0.75,
        'employer_rate' => 3.25,
    ],

    /*
    | Professional tax. `basis` monthly: slab on the month's gross.
    | half_yearly: slab on six months' gross, deducted in `months`.
    | `special_months` overrides the amount in a month (e.g. February top-up
    | so the annual total reaches ₹2,500).
    */
    'pt' => [
        'Karnataka' => ['basis' => 'monthly', 'slabs' => [[0, 24999, 0], [25000, null, 200]], 'special_months' => [2 => 300]],
        'Maharashtra' => ['basis' => 'monthly', 'slabs' => [[0, 7500, 0], [7501, 10000, 175], [10001, null, 200]], 'special_months' => [2 => 300],
            'female_slabs' => [[0, 25000, 0], [25001, null, 200]]],
        'Telangana' => ['basis' => 'monthly', 'slabs' => [[0, 15000, 0], [15001, 20000, 150], [20001, null, 200]]],
        'Andhra Pradesh' => ['basis' => 'monthly', 'slabs' => [[0, 15000, 0], [15001, 20000, 150], [20001, null, 200]]],
        'West Bengal' => ['basis' => 'monthly', 'slabs' => [[0, 10000, 0], [10001, 15000, 110], [15001, 25000, 130], [25001, 40000, 150], [40001, null, 200]]],
        'Gujarat' => ['basis' => 'monthly', 'slabs' => [[0, 11999, 0], [12000, null, 200]]],
        'Madhya Pradesh' => ['basis' => 'monthly', 'slabs' => [[0, 18750, 0], [18751, 25000, 125], [25001, 33333, 167], [33334, null, 208]], 'special_months' => [3 => 212]],
        'Tamil Nadu' => ['basis' => 'half_yearly', 'months' => [9, 3], 'slabs' => [[0, 21000, 0], [21001, 30000, 180], [30001, 45000, 425], [45001, 60000, 930], [60001, 75000, 1025], [75001, null, 1250]]],
        'Kerala' => ['basis' => 'half_yearly', 'months' => [8, 2], 'slabs' => [[0, 11999, 0], [12000, 17999, 320], [18000, 29999, 450], [30000, 44999, 600], [45000, 59999, 750], [60000, 74999, 1000], [75000, null, 1250]]],
        'Odisha' => ['basis' => 'monthly', 'slabs' => [[0, 13304, 0], [13305, 25000, 125], [25001, null, 200]], 'special_months' => [2 => 300]],
        'Assam' => ['basis' => 'monthly', 'slabs' => [[0, 15000, 0], [15001, 25000, 180], [25001, null, 208]], 'special_months' => [3 => 212]],
    ],

    /*
    | Income tax (TDS on salary, section 192), annual figures.
    */
    'income_tax' => [
        'new' => [
            'standard_deduction' => 75000,
            'slabs' => [[400000, 0], [800000, 5], [1200000, 10], [1600000, 15], [2000000, 20], [2400000, 25], [null, 30]],
            'rebate_limit' => 1200000,  // 87A: no tax up to this taxable income (with marginal relief above)
            'rebate_max' => 60000,
        ],
        'old' => [
            'standard_deduction' => 50000,
            'slabs' => [[250000, 0], [500000, 5], [1000000, 20], [null, 30]],
            'rebate_limit' => 500000,
            'rebate_max' => 12500,
        ],
        'cess' => 4,
        'surcharge' => [[5000000, 0], [10000000, 10], [20000000, 15], [null, 25]],
    ],

];
