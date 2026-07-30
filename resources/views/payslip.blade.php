<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip - {{ $run->month }}/{{ $run->year }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #333; padding: 20px; }
        .header { text-align: center; border-bottom: 2px solid #2c3e50; padding-bottom: 15px; margin-bottom: 20px; }
        .header .company-name { font-size: 20px; font-weight: bold; color: #2c3e50; }
        .header .branch-name { font-size: 14px; color: #555; margin-top: 4px; }
        .header .payslip-title { font-size: 16px; font-weight: bold; color: #e74c3c; margin-top: 10px; letter-spacing: 2px; }
        .header .period { font-size: 13px; color: #555; margin-top: 4px; }
        .section { margin-bottom: 16px; }
        .section-title { font-size: 12px; font-weight: bold; background-color: #2c3e50; color: #fff; padding: 5px 10px; margin-bottom: 8px; }
        .employee-grid { display: table; width: 100%; border-collapse: collapse; }
        .employee-row { display: table-row; }
        .employee-cell { display: table-cell; padding: 4px 8px; width: 50%; vertical-align: top; }
        .employee-label { color: #777; font-size: 10px; }
        .employee-value { font-weight: bold; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table th { background-color: #ecf0f1; font-weight: bold; padding: 6px 10px; text-align: left; border: 1px solid #ddd; font-size: 11px; }
        table td { padding: 5px 10px; border: 1px solid #ddd; font-size: 11px; }
        table tr:nth-child(even) { background-color: #f9f9f9; }
        table td.amount { text-align: right; font-weight: bold; }
        .two-col { display: table; width: 100%; }
        .col { display: table-cell; width: 50%; vertical-align: top; padding-right: 10px; }
        .col:last-child { padding-right: 0; padding-left: 10px; }
        .summary-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .summary-table td { padding: 7px 10px; border: 1px solid #ddd; font-size: 12px; }
        .summary-table .label { color: #555; }
        .summary-table .value { text-align: right; font-weight: bold; font-size: 13px; }
        .net-pay-row { background-color: #2c3e50; color: #fff; }
        .net-pay-row td { font-size: 14px; font-weight: bold; }
        .footer { margin-top: 30px; border-top: 1px solid #ddd; padding-top: 10px; text-align: center; color: #888; font-size: 10px; }
        .logo-placeholder { width: 60px; height: 60px; background-color: #ecf0f1; border: 1px dashed #bbb; display: inline-block; text-align: center; line-height: 60px; font-size: 10px; color: #aaa; vertical-align: middle; margin-right: 15px; }
    </style>
</head>
<body>

<!-- Header -->
<div class="header">
    <div>
        <span class="logo-placeholder">LOGO</span>
        <span style="vertical-align: middle; display: inline-block;">
            <div class="company-name">{{ $employee->branch?->company?->name ?? 'Company' }}</div>
            <div class="branch-name">{{ $employee->branch?->name ?? '' }}{{ $employee->branch?->city ? ', ' . $employee->branch->city : '' }}</div>
        </span>
    </div>
    <div class="payslip-title">PAYSLIP</div>
    <div class="period">For the month of {{ \Carbon\Carbon::create($run->year, $run->month, 1)->format('F Y') }}</div>
</div>

<!-- Employee Details -->
<div class="section">
    <div class="section-title">EMPLOYEE DETAILS</div>
    <table>
        <tr>
            <td style="width:25%;"><span class="employee-label">Employee Name</span><br><span class="employee-value">{{ $employee->first_name }} {{ $employee->last_name }}</span></td>
            <td style="width:25%;"><span class="employee-label">Employee Code</span><br><span class="employee-value">{{ $employee->employee_code }}</span></td>
            <td style="width:25%;"><span class="employee-label">Designation</span><br><span class="employee-value">{{ $employee->designation?->name ?? '-' }}</span></td>
            <td style="width:25%;"><span class="employee-label">Department</span><br><span class="employee-value">{{ $employee->department?->name ?? '-' }}</span></td>
        </tr>
        <tr>
            <td><span class="employee-label">Date of Joining</span><br><span class="employee-value">{{ $employee->date_of_joining?->format('d M Y') ?? '-' }}</span></td>
            <td><span class="employee-label">Branch</span><br><span class="employee-value">{{ $employee->branch?->name ?? '-' }}</span></td>
            <td><span class="employee-label">Currency</span><br><span class="employee-value">{{ $payslip->currency_code }}</span></td>
            <td><span class="employee-label">Pay Period</span><br><span class="employee-value">{{ \Carbon\Carbon::create($run->year, $run->month, 1)->format('M Y') }}</span></td>
        </tr>
    </table>
</div>

<!-- Earnings and Deductions side by side -->
<div class="two-col">
    <div class="col">
        <div class="section-title">EARNINGS</div>
        <table>
            <thead>
                <tr>
                    <th>Component</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @php $earnings = $payslip->breakdown_json['earnings'] ?? []; $otPay = $payslip->breakdown_json['ot_pay'] ?? 0; @endphp
                @foreach($earnings as $earning)
                <tr>
                    <td>{{ $earning['name'] }}</td>
                    <td class="amount">{{ number_format($earning['amount'], 2) }}</td>
                </tr>
                @endforeach
                @if($otPay > 0)
                <tr>
                    <td>Overtime Pay</td>
                    <td class="amount">{{ number_format($otPay, 2) }}</td>
                </tr>
                @endif
                <tr style="background-color:#d5e8d4; font-weight:bold;">
                    <td>Gross Pay</td>
                    <td class="amount">{{ number_format($payslip->gross_pay + $otPay, 2) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="col">
        <div class="section-title">DEDUCTIONS</div>
        <table>
            <thead>
                <tr>
                    <th>Component</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @php $deductions = $payslip->breakdown_json['deductions'] ?? []; $statutory = $payslip->breakdown_json['statutory_deductions'] ?? []; @endphp
                @foreach($statutory as $ded)
                <tr>
                    <td>{{ $ded['rule_type'] }}</td>
                    <td class="amount">{{ number_format($ded['amount'], 2) }}</td>
                </tr>
                @endforeach
                @foreach($deductions as $ded)
                <tr>
                    <td>{{ $ded['name'] }}</td>
                    <td class="amount">{{ number_format($ded['amount'], 2) }}</td>
                </tr>
                @endforeach
                @php $lop = $payslip->breakdown_json['lop'] ?? null; @endphp
                @if($lop && ($lop['amount'] ?? 0) > 0)
                <tr>
                    <td>Loss of Pay ({{ rtrim(rtrim(number_format($lop['days'], 1), '0'), '.') }} day{{ $lop['days'] == 1 ? '' : 's' }})</td>
                    <td class="amount">{{ number_format($lop['amount'], 2) }}</td>
                </tr>
                @endif
                <tr style="background-color:#f8cecc; font-weight:bold;">
                    <td>Total Deductions</td>
                    <td class="amount">{{ number_format($payslip->total_deductions, 2) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Net Pay Summary -->
<div class="section" style="margin-top:16px;">
    <div class="section-title">SUMMARY</div>
    <table class="summary-table">
        <tr>
            <td class="label">Gross Pay</td>
            <td class="value">{{ $payslip->currency_code }} {{ number_format($payslip->gross_pay, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Overtime Pay</td>
            <td class="value">{{ $payslip->currency_code }} {{ number_format($payslip->breakdown_json['ot_pay'] ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Total Deductions</td>
            <td class="value">{{ $payslip->currency_code }} {{ number_format($payslip->total_deductions, 2) }}</td>
        </tr>
        <tr class="net-pay-row">
            <td><strong>NET PAY</strong></td>
            <td class="value"><strong>{{ $payslip->currency_code }} {{ number_format($payslip->net_pay, 2) }}</strong></td>
        </tr>
    </table>
</div>

<!-- Footer -->
<div class="footer">
    <p>This is a computer-generated document. No signature is required.</p>
    <p style="margin-top:4px;">Generated on {{ now()->format('d M Y H:i') }}</p>
</div>

</body>
</html>
