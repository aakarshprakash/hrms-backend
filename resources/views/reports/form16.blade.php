<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Form 16 Part B — {{ $f['employee']['name'] }}</title>
<style>
    @page { margin: 28px 30px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #0f172a; }
    h1 { font-size: 14px; text-align: center; margin: 0; }
    .sub { text-align: center; color: #475569; margin: 2px 0 12px; }
    .grid { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .grid td { border: 1px solid #cbd5e1; padding: 5px 6px; vertical-align: top; width: 50%; }
    .l { font-size: 7.5px; color: #64748b; text-transform: uppercase; }
    .v { font-weight: bold; }
    h2 { font-size: 10px; background: #1d4ed8; color: #fff; padding: 4px 6px; margin: 12px 0 0; }
    table.t { width: 100%; border-collapse: collapse; }
    table.t th { background: #f1f5f9; text-align: left; padding: 4px 6px; border: 1px solid #cbd5e1; font-size: 8px; }
    table.t td { padding: 4px 6px; border: 1px solid #e2e8f0; }
    .r { text-align: right; }
    .b td { font-weight: bold; background: #eef2ff; }
    .note { color: #64748b; font-size: 7.5px; margin-top: 10px; }
    .sign { margin-top: 30px; width: 100%; }
</style>
</head>
<body>
@php($c = $f['computation'])
@php($m = fn ($v) => number_format((float) $v, 2))
<h1>FORM No. 16 — PART B</h1>
<div class="sub">Details of salary paid and tax deducted · Financial year {{ $f['financial_year'] }} · Assessment year {{ $f['assessment_year'] }}</div>

<table class="grid">
    <tr>
        <td><div class="l">Employer (deductor)</div><div class="v">{{ $f['employer']['name'] }}</div><div>{{ $f['employer']['address'] }}</div></td>
        <td><div class="l">Employee</div><div class="v">{{ $f['employee']['name'] }} ({{ $f['employee']['code'] }})</div><div>{{ $f['employee']['designation'] }}</div><div>{{ $f['employee']['address'] }}</div></td>
    </tr>
    <tr>
        <td><div class="l">PAN / TAN of the deductor</div><div class="v">{{ $f['employer']['pan'] ?? '— not set —' }} / {{ $f['employer']['tan'] ?? '— not set —' }}</div></td>
        <td><div class="l">PAN of the employee · tax regime</div><div class="v">{{ $f['employee']['pan'] ?? '— not on file —' }} · {{ $f['regime'] === 'old' ? 'Old regime' : 'New regime (115BAC)' }}</div></td>
    </tr>
</table>

<h2>Salary and tax deducted, by month</h2>
<table class="t">
    <thead><tr><th>Month</th><th>Quarter</th><th class="r">Gross salary</th><th class="r">Taxable salary</th><th class="r">PF (employee)</th><th class="r">Professional tax</th><th class="r">TDS</th></tr></thead>
    <tbody>
        @forelse ($f['months'] as $row)
            <tr><td>{{ $row['label'] }}</td><td>Q{{ $row['quarter'] }}</td><td class="r">{{ $m($row['gross']) }}</td><td class="r">{{ $m($row['taxable']) }}</td><td class="r">{{ $m($row['pf']) }}</td><td class="r">{{ $m($row['pt']) }}</td><td class="r">{{ $m($row['tds']) }}</td></tr>
        @empty
            <tr><td colspan="7" style="text-align: center; color: #64748b;">No finalized payroll in this financial year.</td></tr>
        @endforelse
    </tbody>
    <tr class="b"><td colspan="2">Total</td><td class="r">{{ $m(collect($f['months'])->sum('gross')) }}</td><td class="r">{{ $m(collect($f['months'])->sum('taxable')) }}</td><td class="r">{{ $m(collect($f['months'])->sum('pf')) }}</td><td class="r">{{ $m(collect($f['months'])->sum('pt')) }}</td><td class="r">{{ $m(collect($f['months'])->sum('tds')) }}</td></tr>
</table>

<h2>Computation of income and tax</h2>
<table class="t">
    <tr><td>1. Gross salary (section 17(1))</td><td class="r">{{ $m($c['gross_salary']) }}</td></tr>
    <tr><td>2. Less: standard deduction (section 16(ia))</td><td class="r">{{ $m($c['standard_deduction']) }}</td></tr>
    @if ($f['regime'] === 'old')
        <tr><td>3. Less: tax on employment / professional tax (section 16(iii))</td><td class="r">{{ $m($c['professional_tax']) }}</td></tr>
        <tr><td>4. Less: deductions under Chapter VI-A (and HRA / home-loan interest declared)</td><td class="r">{{ $m($c['chapter_via']) }}</td></tr>
        @foreach ($c['chapter_via_lines'] as $line)
            <tr><td style="padding-left: 22px; color: #475569;">{{ $line['label'] }}</td><td class="r" style="color: #475569;">{{ $m($line['eligible']) }}</td></tr>
        @endforeach
    @endif
    <tr class="b"><td>Total taxable income</td><td class="r">{{ $m($c['taxable_income']) }}</td></tr>
    <tr><td>Tax on total income (after rebate u/s 87A, with surcharge and 4% health &amp; education cess)</td><td class="r">{{ $m($c['tax_payable']) }}</td></tr>
    <tr><td>Tax deducted at source (sum of the months above)</td><td class="r">{{ $m($c['tds_deducted']) }}</td></tr>
    <tr class="b"><td>{{ $c['balance'] >= 0 ? 'Tax still payable' : 'Excess tax deducted (refundable)' }}</td><td class="r">{{ $m(abs($c['balance'])) }}</td></tr>
</table>

<h2>TDS by quarter</h2>
<table class="t">
    <thead><tr><th>Quarter</th><th class="r">Amount paid / credited</th><th class="r">Tax deducted</th></tr></thead>
    @foreach ($f['quarters'] as $q)
        <tr><td>{{ $q['quarter'] }}</td><td class="r">{{ $m($q['taxable']) }}</td><td class="r">{{ $m($q['tds']) }}</td></tr>
    @endforeach
</table>

<p class="note">Prepared from finalized payroll in {{ config('app.name') }}. Part A of Form 16 (with challan and deposit details) is downloaded from TRACES. Figures for the current year are provisional until the year is closed.</p>
<table class="sign"><tr><td>Place: ____________ &nbsp;&nbsp; Date: {{ now()->format('d/m/Y') }}</td><td style="text-align: right;">______________________________<br>Signature of the person responsible for deduction of tax</td></tr></table>
</body>
</html>
