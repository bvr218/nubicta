<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @php($wide = count($columns) > 14)
        @page { size: {{ $wide ? 'A3 landscape' : ($landscape ? 'A4 landscape' : 'A4 portrait') }}; margin: {{ $wide ? '8mm 6mm 10mm' : '10mm 9mm 12mm' }}; }
        * { box-sizing: border-box; }
        body { font: {{ $wide ? '7px' : '9px' }}/1.3 Arial, Helvetica, sans-serif; color: #2d3748; margin: 0; }
        .head { display: flex; align-items: center; gap: 14px; border-bottom: 2px solid #0275b3; padding-bottom: 8px; margin-bottom: 8px; }
        .head img { max-height: 46px; max-width: 150px; }
        .head .co { flex: 1; }
        .head .co b { font-size: 12px; display: block; }
        .head .when { text-align: right; color: #718096; font-size: 8.5px; }
        h1 { font-size: 13px; margin: 2px 0 4px; color: #1a4b8c; }
        .filters { color: #4a5568; margin-bottom: 8px; }
        .filters span { margin-right: 12px; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { background: #f0f9ff; color: #1a4b8c; text-align: left; padding: {{ $wide ? '2px 3px' : '4px 5px' }}; border-bottom: 1px solid #b8d4ea; font-weight: bold; }
        td { padding: {{ $wide ? '2px 3px' : '3px 5px' }}; border-bottom: 1px solid #e2e8f0; vertical-align: top; word-break: break-word; }
        .r { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; border-top: 1.5px solid #1a4b8c; background: #f7fafc; }
        .note { margin-top: 6px; color: #718096; }
    </style>
</head>
<body>
<div class="head">
    @if ($company && $company['logo'])<img src="{{ $company['logo'] }}" alt="">@endif
    <div class="co">
        <b>{{ $company['name'] ?? '' }}</b>
        @if ($company)NIT {{ $company['nit'] }}@if ($company['address']) · {{ $company['address'] }}@endif @if ($company['city']) · {{ $company['city'] }}@endif @endif
    </div>
    <div class="when">Generado el {{ now()->format('d/m/Y h:i a') }}</div>
</div>
<h1>{{ $title }}</h1>
@if ($filters)
    <div class="filters">@foreach ($filters as $k => $v)<span><b>{{ $k }}:</b> {{ $v }}</span>@endforeach</div>
@endif
@php($numeric = fn ($t) => in_array($t, ['money', 'qty', 'int', 'pct'], true))
<table>
    <thead><tr>@foreach ($columns as $c)<th @class(['r' => $numeric($c[1])])>{{ $c[0] }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse ($rows as $r)
        <tr>@foreach ($columns as $i => $c)<td @class(['r' => $numeric($c[1])])>{{ \App\Services\Reports\TablePdf::format($r[$i] ?? null, $c[1]) }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count($columns) }}" style="text-align:center;padding:16px;color:#718096">No hay información para los filtros seleccionados</td></tr>
    @endforelse
    </tbody>
    @if ($totals !== null && $rows)
        <tfoot><tr>@foreach ($columns as $i => $c)<td @class(['r' => $numeric($c[1])])>{{ $i === 0 && ($totals[0] ?? null) === null ? 'Total' : \App\Services\Reports\TablePdf::format($totals[$i] ?? null, $c[1]) }}</td>@endforeach</tr></tfoot>
    @endif
</table>
@if ($truncated)<p class="note">El PDF muestra las primeras {{ number_format(\App\Services\Reports\TablePdf::MAX_ROWS) }} filas. Descarga el Excel para ver el reporte completo.</p>@endif
</body>
</html>
