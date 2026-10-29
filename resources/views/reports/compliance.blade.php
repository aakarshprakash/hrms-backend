<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 22px 22px 34px 22px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #0f172a; }
    .head { border-bottom: 2px solid #1d4ed8; padding-bottom: 6px; margin-bottom: 8px; }
    .head table { width: 100%; }
    .company { font-size: 13px; font-weight: bold; }
    .muted { color: #64748b; }
    .title { font-size: 12px; font-weight: bold; text-align: right; }
    .ids span { margin-right: 14px; }
    .boxes { width: 100%; margin: 6px 0 10px; border-collapse: separate; border-spacing: 6px 0; }
    .boxes td { background: #f1f5f9; padding: 6px 8px; border-radius: 4px; }
    .boxes .l { font-size: 7px; color: #64748b; text-transform: uppercase; }
    .boxes .v { font-size: 11px; font-weight: bold; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #f1f5f9; color: #334155; text-align: left; padding: 4px 3px; border-bottom: 1px solid #cbd5e1; font-size: 7px; text-transform: uppercase; }
    table.data td { padding: 3px; border-bottom: 1px solid #e2e8f0; }
    table.data tr:nth-child(even) td { background: #fafbfc; }
    .r { text-align: right; }
    tfoot td { font-weight: bold; background: #eef2ff !important; border-top: 1px solid #94a3b8; }
    .sign { margin-top: 28px; width: 100%; }
    .sign td { width: 50%; vertical-align: bottom; }
    .footer { position: fixed; bottom: -22px; left: 0; right: 0; font-size: 7px; color: #94a3b8; }
    .pagenum:before { content: counter(page); }
</style>
</head>
<body>
<div class="footer">Generated {{ now()->format('d M Y, h:i A') }} by {{ $generatedBy }} · {{ config('app.name') }} · Page <span class="pagenum"></span></div>

<div class="head">
    <table>
        <tr>
            <td>
                <div class="company">{{ $company?->legal_name ?: $company?->name }}</div>
                <div class="muted">{{ collect([$company?->address_line1, $company?->city, $company?->state])->filter()->implode(', ') }}</div>
                <div class="muted ids">
                    @if (! empty($company?->statutory['pf_establishment_code']))<span>PF code: {{ $company->statutory['pf_establishment_code'] }}</span>@endif
                    @if (! empty($company?->statutory['esi_employer_code']))<span>ESI code: {{ $company->statutory['esi_employer_code'] }}</span>@endif
                    @if (! empty($company?->statutory['tan']))<span>TAN: {{ $company->statutory['tan'] }}</span>@endif
                    @if (! empty($company?->statutory['pt_registration']))<span>PT reg.: {{ $company->statutory['pt_registration'] }}</span>@endif
                </div>
            </td>
            <td class="title">{{ $title }}<br><span class="muted" style="font-weight: normal; font-size: 9px;">{{ $period }}</span></td>
        </tr>
    </table>
</div>

@if (! empty($boxes))
    <table class="boxes"><tr>
        @foreach ($boxes as $label => $value)
            <td><div class="l">{{ $label }}</div><div class="v">{{ $value }}</div></td>
        @endforeach
    </tr></table>
@endif

<table class="data">
    <thead>
        <tr>@foreach ($header as $i => $h)<th class="{{ in_array($i, $numeric, true) ? 'r' : '' }}">{{ $h }}</th>@endforeach</tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>@foreach (array_values($row) as $i => $cell)<td class="{{ in_array($i, $numeric, true) ? 'r' : '' }}">{{ is_float($cell) ? number_format($cell, 2) : $cell }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($header) }}" class="muted" style="text-align: center; padding: 16px;">No records.</td></tr>
        @endforelse
    </tbody>
    @if (count($rows) && ! empty($numeric))
        <tfoot>
            <tr>
                @foreach ($header as $i => $h)
                    <td class="{{ in_array($i, $numeric, true) ? 'r' : '' }}">
                        @if ($i === 0) Total ({{ count($rows) }}) @elseif (in_array($i, $numeric, true)) {{ number_format(collect($rows)->sum(fn ($r) => (float) (array_values($r)[$i] ?? 0)), 2) }} @endif
                    </td>
                @endforeach
            </tr>
        </tfoot>
    @endif
</table>

<table class="sign">
    <tr>
        <td class="muted">Prepared from finalized payroll. Verify against the portal before filing.</td>
        <td style="text-align: right;">______________________________<br>Authorised signatory</td>
    </tr>
</table>
</body>
</html>
