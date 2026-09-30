<?php

namespace Tests\Unit;

use App\Services\Payroll\StatutoryCalculator;
use App\Support\IndianNumberToWords;
use Tests\TestCase;

/**
 * Figures below are worked out by hand from the statutory rules, so a
 * regression in any formula shows up as a plain number mismatch.
 */
class StatutoryCalculatorTest extends TestCase
{
    private StatutoryCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new StatutoryCalculator();
    }

    public function test_pf_is_capped_at_the_wage_ceiling_and_split_into_eps_and_epf(): void
    {
        $pf = $this->calc->pf(30000);

        $this->assertSame(15000.0, $pf['wage']);
        $this->assertEquals(1800, $pf['employee']);      // 12% of 15,000
        $this->assertEquals(1250, $pf['employer_eps']);  // 8.33% of 15,000, max 1,250
        $this->assertEquals(550, $pf['employer_epf']);   // 1,800 - 1,250
        $this->assertEquals(75, $pf['edli']);
        $this->assertEquals(75, $pf['admin']);
    }

    public function test_pf_below_the_ceiling_and_on_full_wage_when_configured(): void
    {
        $pf = $this->calc->pf(12000);
        $this->assertEquals(1440, $pf['employee']);
        $this->assertEquals(1000, $pf['employer_eps']);  // 999.6 rounded
        $this->assertEquals(440, $pf['employer_epf']);

        $full = $this->calc->pf(30000, ['restrict_to_ceiling' => false]);
        $this->assertEquals(3600, $full['employee']);
        $this->assertEquals(1250, $full['employer_eps']); // EPS stays on the ceiling
    }

    public function test_esi_applies_only_up_to_the_wage_ceiling_and_rounds_up(): void
    {
        $esi = $this->calc->esi(18000, 17500.40);
        $this->assertTrue($esi['eligible']);
        $this->assertEquals(132, $esi['employee']);  // 131.25 -> 132
        $this->assertEquals(569, $esi['employer']);  // 568.76 -> 569

        $this->assertFalse($this->calc->esi(25000, 25000)['eligible']);
    }

    public function test_professional_tax_by_state(): void
    {
        $karnataka = ['state' => 'Karnataka'];
        $this->assertEquals(200, $this->calc->professionalTax(30000, 1, $karnataka));
        $this->assertEquals(300, $this->calc->professionalTax(30000, 2, $karnataka)); // February top-up
        $this->assertEquals(0, $this->calc->professionalTax(20000, 1, $karnataka));

        $kerala = ['state' => 'Kerala'];
        $this->assertEquals(1250, $this->calc->professionalTax(20000, 8, $kerala)); // half-year income 1.2L
        $this->assertEquals(0, $this->calc->professionalTax(20000, 9, $kerala));    // not a deduction month

        $maharashtra = ['state' => 'Maharashtra'];
        $this->assertEquals(200, $this->calc->professionalTax(20000, 5, $maharashtra, 'male'));
        $this->assertEquals(0, $this->calc->professionalTax(20000, 5, $maharashtra, 'female'));
    }

    public function test_new_regime_income_tax_with_rebate_and_marginal_relief(): void
    {
        $this->assertEquals(0, $this->calc->annualIncomeTax(1200000, 'new'));
        // Slab tax 61,500 but relief caps it at income above 12L (10,000), plus 4% cess.
        $this->assertEquals(10400, $this->calc->annualIncomeTax(1210000, 'new'));
        // 20,000 + 40,000 + 45,000 = 1,05,000 + cess
        $this->assertEquals(109200, $this->calc->annualIncomeTax(1500000, 'new'));
    }

    public function test_old_regime_income_tax(): void
    {
        $this->assertEquals(0, $this->calc->annualIncomeTax(500000, 'old'));
        $this->assertEquals(54600, $this->calc->annualIncomeTax(700000, 'old')); // 12,500 + 40,000 + cess
    }

    public function test_monthly_tds_spreads_the_annual_tax_over_remaining_months(): void
    {
        $tds = $this->calc->monthlyTds([
            'regime' => 'new', 'ytd_taxable' => 0, 'ytd_tds' => 0,
            'current_taxable' => 150000, 'monthly_taxable_fixed' => 150000, 'months_remaining' => 12,
        ]);

        // 18L - 75k = 17.25L -> 20k + 40k + 60k + 25k = 1,45,000 + cess = 1,50,800
        $this->assertEquals(1725000, $tds['taxable_income']);
        $this->assertEquals(150800, $tds['annual_tax']);
        $this->assertEquals(12567, $tds['amount']);

        // Later in the year, tax already deducted is taken into account.
        $later = $this->calc->monthlyTds([
            'regime' => 'new', 'ytd_taxable' => 900000, 'ytd_tds' => 75402,
            'current_taxable' => 150000, 'monthly_taxable_fixed' => 150000, 'months_remaining' => 6,
        ]);
        $this->assertEquals(12566, $later['amount']);
    }

    public function test_amount_in_words_uses_indian_numbering(): void
    {
        $this->assertSame('One Lakh Twenty Five Thousand Four Hundred Thirty Rupees and Fifty Paise Only', IndianNumberToWords::rupees(125430.50));
        $this->assertSame('Two Crore Five Lakh Rupees Only', IndianNumberToWords::rupees(20500000));
        $this->assertSame('Zero Rupees Only', IndianNumberToWords::rupees(0));
    }
}
