<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $report['title'] }}</title>
<style>
    @page { margin: 22px 22px 34px 22px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #0f172a; }
    .head { border-bottom: 2px solid #1d4ed8; padding-bottom: 6px; margin-bottom: 8px; }
    .head table { width: 100%; }
    .company { font-size: 13px; font-weight: bold; }
    .muted { color: #64748b; }
    .title { font-size: 12px; font-weight: bold; text-align: right; }
    .filters { margin-bottom: 8px; color: #475569; }
    .filters span { margin-right: 14px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #f1f5f9; color: #334155; font-weight: bold; text-align: left; padding: 4px 3px; border-bottom: 1px solid #cbd5e1; font-size: 7.5px; text-transform: uppercase; }
    table.data td { padding: 3px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    table.data tr:nth-child(even) td { background: #fafbfc; }
    .r { text-align: right; }
    .c { text-align: center; }
    .b { font-weight: bold; }
    tfoot td { font-weight: bold; background: #eef2ff !important; border-top: 1px solid #94a3b8; }
    .grid td, .grid th { padding: 2px 1px; font-size: 7px; text-align: center; }
    .grid td.name, .grid th.name { text-align: left; padding-left: 3px; }
    .A { color: #be123c; font-weight: bold; } .L { color: #7c3aed; } .WO, .H { color: #64748b; } .HD { color: #b45309; }
    .footer { position: fixed; bottom: -22px; left: 0; right: 0; font-size: 7px; color: #94a3b8; }
    .pagenum:before { content: counter(page); }
    .legend span { margin-right: 10px; }
</style>
</head>
<body>
<div class="footer">
    Generated {{ now()->format('d M Y, h:i A') }} by {{ $generatedBy }} · {{ config('app.name') }} · Page <span class="pagenum"></span>
</div>

<div class="head">
    <table>
        <tr>
            <td>
                <div class="company">{{ $company?->legal_name ?: $company?->name }}</div>
                <div class="muted">{{ collect([$company?->address_line1, $company?->city, $company?->state])->filter()->implode(', ') }}</div>
            </td>
            <td class="title">
                {{ $report['title'] }}<br>
                <span class="muted" style="font-weight: normal; font-size: 9px;">
                    {{ \Carbon\Carbon::parse($report['period']['from'])->format('d M Y') }} – {{ \Carbon\Carbon::parse($report['period']['to'])->format('d M Y') }}
                    ({{ $report['period']['days'] }} days)
                </span>
            </td>
        </tr>
    </table>
</div>

<div class="filters">
    @foreach ($filters as $label => $value)
        <span><b>{{ $label }}:</b> {{ $value }}</span>
    @endforeach
    <span><b>Rows:</b> {{ count($report['rows']) }}</span>
</div>

@php
    $fmt = function ($row, $col) {
        $v = $row[$col['key']] ?? null;
        if ($v === null || $v === '') return '';
        if (($col['type'] ?? null) === 'percent') return $v . '%';
        if (($col['type'] ?? null) === 'date') return \Carbon\Carbon::parse($v)->format('d M Y');
        return is_float($v) ? rtrim(rtrim(number_format($v, 2), '0'), '.') : $v;
    };
@endphp

@if ($report['layout'] === 'grid')
    <table class="data grid">
        <thead>
            <tr>
                <th class="name">Code</th>
                <th class="name">Employee</th>
                @foreach ($report['dates'] as $d)
                    <th>{{ \Carbon\Carbon::parse($d)->format('d') }}<br>{{ substr(\Carbon\Carbon::parse($d)->format('D'), 0, 2) }}</th>
                @endforeach
                @foreach (array_slice($report['columns'], 2) as $col)
                    <th>{{ $col['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($report['rows'] as $row)
                <tr>
                    <td class="name">{{ $row['employee_code'] }}</td>
                    <td class="name">{{ $row['name'] }}</td>
                    @foreach ($report['dates'] as $d)
                        @php $code = $row['cells'][$d]['code'] ?? ''; @endphp
                        <td class="{{ $code }}">{{ $code }}</td>
                    @endforeach
                    @foreach (array_slice($report['columns'], 2) as $col)
                        <td class="{{ ! empty($col['emphasis']) ? 'b' : '' }}">{{ $fmt($row, $col) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="legend muted" style="margin-top: 6px;">
        @foreach ($report['legend'] as $code => $label)<span><b>{{ $code }}</b> {{ $label }}</span>@endforeach
    </p>
@else
    <table class="data">
        <thead>
            <tr>
                @foreach ($report['columns'] as $col)
                    <th class="{{ ($col['align'] ?? '') === 'right' ? 'r' : '' }}">{{ $col['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    @foreach ($report['columns'] as $col)
                        <td class="{{ ($col['align'] ?? '') === 'right' ? 'r' : '' }} {{ ! empty($col['emphasis']) ? 'b' : '' }}">{{ $fmt($row, $col) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($report['columns']) }}" class="c muted" style="padding: 18px;">No records for this period and filter.</td></tr>
            @endforelse
        </tbody>
        @if (! empty($report['totals']) && count($report['rows']))
            <tfoot>
                <tr>
                    @foreach ($report['columns'] as $i => $col)
                        <td class="{{ ($col['align'] ?? '') === 'right' ? 'r' : '' }}">
                            @if ($i === 0) Total @elseif (array_key_exists($col['key'], $report['totals'])) {{ $fmt($report['totals'], $col) }} @endif
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
@endif
</body>
</html>
