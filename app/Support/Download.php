<?php

namespace App\Support;

use App\Exports\ArrayExport;
use App\Services\Reports\TablePdf;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Descarga de listados en Excel o PDF segun ?formato=xlsx|pdf. */
class Download
{
    public static function wantsPdf(): bool
    {
        return request('formato') === 'pdf';
    }

    /**
     * Exporta un objeto de maatwebsite/excel (ArrayExport o FromQuery con encabezados y mapeo).
     * En PDF los formatos se toman de ArrayExport o se deducen de los valores.
     */
    public static function export(object $export, string $file, string $title, array $filters = []): BinaryFileResponse
    {
        if (! self::wantsPdf()) {
            return Excel::download($export, $file);
        }
        $headings = $export instanceof WithHeadings ? $export->headings() : [];
        if ($export instanceof ArrayExport) {
            [$rows, $formats, $totals] = [$export->rowsOnly(), $export->formats(), $export->totalsRow()];
        } else {
            $source = match (true) {
                $export instanceof FromQuery => $export->query()->lazy(1000),
                $export instanceof FromArray => $export->array(),
                default => [],
            };
            $rows = [];
            foreach ($source as $r) {
                $rows[] = array_values($export instanceof WithMapping ? $export->map($r) : (array) $r);
                if (count($rows) >= TablePdf::MAX_ROWS) {
                    break;
                }
            }
            $formats = method_exists($export, 'formats') ? array_values($export->formats()) : self::infer($rows);
            $totals = null;
        }
        $columns = array_map(fn ($h, $i) => [(string) $h, $formats[$i] ?? 'text'], $headings, array_keys($headings));

        return TablePdf::download(preg_replace('/\.xlsx$/i', '', $file).'.pdf', $title, $columns, $rows, $totals, $filters);
    }

    /** Misma firma que ArrayExport::download, con PDF cuando se pide. */
    public static function array(string $file, array $headings, iterable $rows, string $title = 'Hoja1', array $formats = [], ?string $caption = null, array $filters = [], ?array $totals = null): BinaryFileResponse
    {
        return self::export(new ArrayExport($headings, $rows, $title, $formats, $caption, $filters, $totals), $file, $caption ?? $title, $filters);
    }

    /** Listado simple: encabezados, filas y formatos opcionales. */
    public static function table(string $file, string $title, array $headings, iterable $rows, array $formats = [], array $filters = [], ?array $totals = null): BinaryFileResponse
    {
        return self::export(new ArrayExport($headings, $rows, mb_substr($title, 0, 31), $formats, $title, $filters, $totals), $file, $title, $filters);
    }

    /** Numeros con decimales como money, enteros como int, AAAA-MM-DD o DD/MM/AAAA como date. */
    private static function infer(array $rows): array
    {
        $out = [];
        foreach (array_slice($rows, 0, 200) as $r) {
            foreach ($r as $i => $v) {
                if ($v === null || $v === '' || isset($out[$i]) && $out[$i] === 'text') {
                    continue;
                }
                $t = match (true) {
                    is_float($v) || is_numeric($v) && str_contains((string) $v, '.') => 'money',
                    is_int($v) => 'int',
                    is_string($v) && preg_match('#^(\d{4}-\d{2}-\d{2}|\d{2}/\d{2}/\d{4})$#', $v) === 1 => 'date',
                    default => 'text',
                };
                $out[$i] = isset($out[$i]) && $out[$i] !== $t ? ($t === 'money' && $out[$i] === 'int' ? 'money' : ($out[$i] === 'money' && $t === 'int' ? 'money' : 'text')) : $t;
            }
        }

        return $out;
    }
}
