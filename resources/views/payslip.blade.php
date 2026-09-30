<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payslip — {{ $p['employee']['name'] }} — {{ $p['period'] }}</title>
<style>
    @page { margin: 28px 32px; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1e293b; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    .muted { color: #64748b; }
    .right { text-align: right; }
    .head td { vertical-align: middle; }
    .company { font-size: 15px; font-weight: bold; color: #0f172a; }
    .addr { font-size: 9px; color: #64748b; margin-top: 2px; }
    .title { font-size: 11px; font-weight: bold; letter-spacing: 2px; color: #2563eb; }
    .period { font-size: 13px; font-weight: bold; color: #0f172a; margin-top: 2px; }
    .rule { height: 3px; background: #2563eb; margin: 10px 0 12px; }
    .card { border: 1px solid #e2e8f0; border-radius: 6px; }
    .info td { padding: 5px 9px; width: 25%; vertical-align: top; }
    .label { font-size: 8px; text-transform: uppercase; letter-spacing: .5px; color: #64748b; }
    .value { font-size: 10px; font-weight: bold; color: #0f172a; margin-top: 1px; }
    .days td { text-align: center; padding: 7px 4px; border-right: 1px solid #e2e8f0; }
    .days td:last-child { border-right: 0; }
    .days .num { font-size: 13px; font-weight: bold; color: #0f172a; }
    .lines th { background: #f1f5f9; font-size: 8.5px; text-transform: uppercase; letter-spacing: .5px; color: #475569; padding: 6px 9px; text-align: left; }
    .lines td { padding: 5px 9px; border-bottom: 1px solid #f1f5f9; }
    .lines .total td { border-top: 1px solid #cbd5e1; border-bottom: 0; font-weight: bold; background: #f8fafc; }
    .net { background: #0f172a; color: #fff; border-radius: 6px; }
    .net td { padding: 11px 14px; }
    .net .amt { font-size: 18px; font-weight: bold; text-align: right; }
    .note { font-size: 8px; color: #94a3b8; }
    .foot { margin-top: 16px; font-size: 8px; color: #94a3b8; text-align: center; }
</style>
</head>
<body>

<table class="head">
    <tr>
        <td style="width: 62%;">
            @if ($p['company']['logo_path'] && file_exists($p['company']['logo_path']))
                <img src="{{ $p['company']['logo_path'] }}" style="height: 34px; margin-bottom: 4px;"><br>
            @endif
            <div class="company">{{ $p['company']['name'] }}</div>
            @if ($p['company']['address'])<div class="addr">{{ $p['company']['address'] }}</div>@endif
            @if ($p['branch'])<div class="addr">Branch: {{ $p['branch'] }}</div>@endif
        </td>
        <td class="right">
            <div class="title">PAYSLIP</div>
            <div class="period">{{ $p['period'] }}</div>
            @if ($p['company']['pf_code'])<div class="addr">PF Code: {{ $p['company']['pf_code'] }}</div>@endif
            @if ($p['company']['esi_code'])<div class="addr">ESI Code: {{ $p['company']['esi_code'] }}</div>@endif
        </td>
    </tr>
</table>
<div class="rule"></div>

<table class="card info">
    <tr>
        <td><div class="label">Employee</div><div class="value">{{ $p['employee']['name'] }}</div></td>
        <td><div class="label">Employee code</div><div class="value">{{ $p['employee']['code'] }}</div></td>
        <td><div class="label">Designation</div><div class="value">{{ $p['employee']['designation'] ?? '—' }}</div></td>
        <td><div class="label">Department</div><div class="value">{{ $p['employee']['department'] ?? '—' }}</div></td>
    </tr>
    <tr>
        <td><div class="label">Date of joining</div><div class="value">{{ $p['employee']['date_of_joining'] ?? '—' }}</div></td>
        <td><div class="label">PAN</div><div class="value">{{ $p['employee']['pan'] ?? '—' }}</div></td>
        <td><div class="label">UAN</div><div class="value">{{ $p['employee']['uan'] ?? '—' }}</div></td>
        <td><div class="label">ESI number</div><div class="value">{{ $p['employee']['esi_number'] ?? '—' }}</div></td>
    </tr>
    <tr>
        <td><div class="label">Bank</div><div class="value">{{ $p['employee']['bank'] ?? '—' }}</div></td>
        <td><div class="label">Account</div><div class="value">{{ $p['employee']['account'] ?? '—' }}</div></td>
        <td><div class="label">Tax regime</div><div class="value">{{ $p['employee']['tax_regime'] }}</div></td>
        <td></td>
    </tr>
</table>

<table class="card days" style="margin-top: 10px;">
    <tr>
        <td><div class="label">Pay days (basis)</div><div class="num">{{ rtrim(rtrim(number_format((float) $p['attendance']['basis_days'], 2), '0'), '.') ?: '—' }}</div></td>
        <td><div class="label">Payable days</div><div class="num">{{ $p['attendance']['payable_days'] !== null ? rtrim(rtrim(number_format((float) $p['attendance']['payable_days'], 2), '0'), '.') : '—' }}</div></td>
        <td><div class="label">Loss of pay</div><div class="num">{{ rtrim(rtrim(number_format((float) $p['attendance']['lop_days'], 2), '0'), '.') ?: '0' }}</div></td>
        @if ($p['attendance']['present_days'] !== null)
            <td><div class="label">Days present</div><div class="num">{{ $p['attendance']['present_days'] }}</div></td>
            <td><div class="label">Paid leave</div><div class="num">{{ $p['attendance']['paid_leave_days'] }}</div></td>
        @endif
    </tr>
</table>

<table style="margin-top: 12px;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 6px;">
            <table class="card lines">
                <tr><th>Earnings</th><th class="right">Rate</th><th class="right">Earned</th></tr>
                @foreach ($p['earnings'] as $e)
                    <tr>
                        <td>{{ $e['name'] }}@if ($e['note'])<br><span class="note">{{ $e['note'] }}</span>@endif</td>
                        <td class="right muted">{{ $e['monthly'] !== null ? number_format($e['monthly'], 2) : '' }}</td>
                        <td class="right">{{ number_format($e['amount'], 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td>Gross earnings</td><td></td><td class="right">{{ number_format($p['gross'], 2) }}</td></tr>
            </table>
        </td>
        <td style="width: 50%; vertical-align: top; padding-left: 6px;">
            <table class="card lines">
                <tr><th>Deductions</th><th class="right">Amount</th></tr>
                @forelse ($p['deductions'] as $d)
                    <tr>
                        <td>{{ $d['name'] }}@if ($d['note'])<br><span class="note">{{ $d['note'] }}</span>@endif</td>
                        <td class="right">{{ number_format($d['amount'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td class="muted">No deductions</td><td></td></tr>
                @endforelse
                <tr class="total"><td>Total deductions</td><td class="right">{{ number_format($p['total_deductions'], 2) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="net" style="margin-top: 12px;">
    <tr>
        <td>
            <div class="label" style="color: #94a3b8;">Net pay</div>
            <div style="font-size: 9px; margin-top: 2px;">{{ $p['net_words'] }}</div>
        </td>
        <td class="amt">{{ $p['currency'] }}{{ number_format($p['net'], 2) }}</td>
    </tr>
</table>

@if (count($p['employer']) > 0)
    <table class="card lines" style="margin-top: 12px;">
        <tr><th>Employer contributions (not deducted from your pay)</th><th class="right">Amount</th></tr>
        @foreach ($p['employer'] as $e)
            <tr><td>{{ $e['name'] }}</td><td class="right">{{ number_format($e['amount'], 2) }}</td></tr>
        @endforeach
    </table>
@endif

@if (($p['tds']['mode'] ?? null) === 'income_tax')
    <p class="note" style="margin-top: 8px;">
        TDS worked out on projected annual taxable income of {{ number_format($p['tds']['taxable_income'], 0) }}
        (annual tax {{ number_format($p['tds']['annual_tax'], 0) }}, deducted so far {{ number_format($p['tds']['tds_to_date'], 0) }},
        {{ $p['tds']['months_remaining'] }} month(s) remaining in the financial year).
    </p>
@endif

<div class="foot">This is a computer-generated payslip and does not require a signature. · Generated {{ $p['generated_at'] }} · Peoplenex HRMS</div>
</body>
</html>
