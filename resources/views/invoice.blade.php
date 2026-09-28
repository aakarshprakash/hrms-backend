@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $buyer = $invoice->buyer ?? [];
    $intra = (float) $invoice->igst == 0.0;
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $invoice->number }}</title>
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 11px; color: #1e293b; margin: 0; }
    .wrap { padding: 28px 32px; }
    .top { width: 100%; border-bottom: 2px solid #2563eb; padding-bottom: 14px; margin-bottom: 16px; }
    .top td { vertical-align: top; }
    h1 { font-size: 20px; margin: 0; color: #0f172a; }
    .muted { color: #64748b; }
    .title { text-align: right; }
    .title h2 { margin: 0; font-size: 16px; letter-spacing: 1px; color: #2563eb; }
    .meta td { padding: 1px 0; }
    .parties { width: 100%; margin-bottom: 16px; }
    .parties td { width: 50%; vertical-align: top; padding-right: 16px; }
    .label { font-size: 9px; text-transform: uppercase; letter-spacing: .6px; color: #64748b; margin-bottom: 3px; }
    table.lines { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    table.lines th { background: #f1f5f9; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #475569; padding: 7px 8px; }
    table.lines td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
    .num { text-align: right; white-space: nowrap; }
    .totals { width: 45%; margin-left: 55%; border-collapse: collapse; }
    .totals td { padding: 4px 8px; }
    .totals .grand td { border-top: 2px solid #0f172a; font-weight: bold; font-size: 13px; padding-top: 7px; }
    .words { margin-top: 10px; padding: 8px 10px; background: #f8fafc; border-radius: 4px; }
    .status { display: inline-block; padding: 3px 10px; border-radius: 10px; font-weight: bold; font-size: 10px; }
    .paid { background: #dcfce7; color: #166534; }
    .issued { background: #fef3c7; color: #92400e; }
    .void { background: #f1f5f9; color: #64748b; }
    .foot { margin-top: 22px; font-size: 9.5px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 10px; }
</style>
</head>
<body>
<div class="wrap">
    <table class="top">
        <tr>
            <td>
                <h1>{{ $seller['name'] }}</h1>
                @if (!empty($seller['address']))<div class="muted">{{ $seller['address'] }}</div>@endif
                <div class="muted">{{ $seller['state'] }}@if (!empty($seller['gstin'])) · GSTIN {{ $seller['gstin'] }}@endif</div>
                @if (!empty($seller['email']))<div class="muted">{{ $seller['email'] }}</div>@endif
            </td>
            <td class="title">
                <h2>TAX INVOICE</h2>
                <table class="meta" style="margin-left:auto">
                    <tr><td class="muted">Invoice no.&nbsp;</td><td><b>{{ $invoice->number }}</b></td></tr>
                    <tr><td class="muted">Date&nbsp;</td><td>{{ $invoice->issued_on->format('d M Y') }}</td></tr>
                    <tr><td class="muted">Due&nbsp;</td><td>{{ $invoice->due_on->format('d M Y') }}</td></tr>
                    <tr><td></td><td><span class="status {{ $invoice->status }}">{{ strtoupper($invoice->status === 'issued' ? 'Unpaid' : $invoice->status) }}</span></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                <div class="label">Billed to</div>
                <b>{{ $buyer['name'] ?? $company->name }}</b><br>
                @if (!empty($buyer['address'])){{ $buyer['address'] }}<br>@endif
                {{ $buyer['state'] ?? '' }}<br>
                @if (!empty($buyer['gstin']))GSTIN {{ $buyer['gstin'] }}@else<span class="muted">Unregistered (no GSTIN)</span>@endif
            </td>
            <td>
                <div class="label">Service period</div>
                {{ $invoice->period_start->format('d M Y') }} – {{ $invoice->period_end->format('d M Y') }}<br><br>
                <div class="label">Place of supply</div>
                {{ $buyer['state'] ?? '—' }}
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr><th>Description</th><th>SAC</th><th class="num">Qty</th><th class="num">Rate (₹)</th><th class="num">Amount (₹)</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line['description'] }}</td>
                    <td>{{ $sac }}</td>
                    <td class="num">{{ $line['quantity'] }}</td>
                    <td class="num">{{ $fmt($line['unit_price']) }}</td>
                    <td class="num">{{ $fmt($line['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Taxable value</td><td class="num">₹ {{ $fmt($invoice->subtotal) }}</td></tr>
        @if ($intra)
            <tr><td>CGST @ {{ $gstRate / 2 }}%</td><td class="num">₹ {{ $fmt($invoice->cgst) }}</td></tr>
            <tr><td>SGST @ {{ $gstRate / 2 }}%</td><td class="num">₹ {{ $fmt($invoice->sgst) }}</td></tr>
        @else
            <tr><td>IGST @ {{ $gstRate }}%</td><td class="num">₹ {{ $fmt($invoice->igst) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="num">₹ {{ $fmt($invoice->total) }}</td></tr>
    </table>

    <div class="words"><span class="muted">Amount in words:</span> {{ \App\Support\IndianNumberToWords::rupees((float) $invoice->total) }}</div>

    @if ($invoice->status === 'paid')
        <p style="margin-top:14px">Paid on {{ $invoice->paid_at?->format('d M Y') }}@if ($invoice->payment_reference) · Ref. {{ $invoice->payment_reference }}@endif.</p>
    @elseif (!empty($seller['bank_details']))
        <p style="margin-top:14px"><b>Pay by bank transfer:</b> {{ $seller['bank_details'] }} — please quote {{ $invoice->number }}.</p>
    @endif

    <div class="foot">
        This is a computer-generated invoice and needs no signature. Subscription for {{ config('app.name') }} HR software.
    </div>
</div>
</body>
</html>
