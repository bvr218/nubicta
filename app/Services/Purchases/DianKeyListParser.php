<?php

namespace App\Services\Purchases;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

class DianKeyListParser
{
    /** @return list<string> */
    public function parse(string $path): array
    {
        try {
            $workbook = IOFactory::load($path);
        } catch (Throwable $e) {
            throw new RuntimeException('No fue posible leer el archivo Excel.', previous: $e);
        }

        try {
            $sheet = $workbook->getActiveSheet();
            $column = null;
            $headerRow = null;
            $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            for ($row = 1; $row <= min(20, $sheet->getHighestDataRow()); $row++) {
                for ($columnIndex = 1; $columnIndex <= $lastColumn; $columnIndex++) {
                    $letter = Coordinate::stringFromColumnIndex($columnIndex);
                    $header = strtoupper(preg_replace('/[^A-Z]/', '', Str::ascii((string) $sheet->getCell($letter.$row)->getValue())));
                    if (in_array($header, ['CUFE', 'CUDE', 'CUFECUDE'], true)) {
                        $column = $letter;
                        $headerRow = $row;
                        break 2;
                    }
                }
            }
            if (! $column || ! $headerRow) {
                throw new RuntimeException('No se encontró una columna titulada CUFE, CUDE o CUFE/CUDE.');
            }

            $keys = [];
            foreach ($sheet->getRowIterator($headerRow + 1) as $row) {
                $value = trim((string) $sheet->getCell($column.$row->getRowIndex())->getValue());
                if ($value === '') {
                    continue;
                }
                if (! preg_match('/^[a-f0-9]{96}$/i', $value)) {
                    throw new RuntimeException("El valor de la fila {$row->getRowIndex()} no es un CUFE/CUDE válido.");
                }
                $key = strtolower($value);
                $keys[$key] = $key;
            }
            if ($keys === []) {
                throw new RuntimeException('La columna seleccionada no contiene CUFE/CUDE.');
            }

            return array_values($keys);
        } finally {
            $workbook->disconnectWorksheets();
        }
    }
}
