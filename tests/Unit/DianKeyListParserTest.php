<?php

namespace Tests\Unit;

use App\Services\Purchases\DianKeyListParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Tests\TestCase;

class DianKeyListParserTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_reads_and_deduplicates_cufe_cude_values(): void
    {
        $first = str_repeat('a', 96);
        $second = str_repeat('b', 96);
        $path = $this->workbook([['Reporte'], ['CUFE/CUDE', 'Otro'], [$first, 'x'], [$second, 'y'], [strtoupper($first), 'z']]);

        try {
            $this->assertSame([$first, $second], (new DianKeyListParser)->parse($path));
        } finally {
            @unlink($path);
        }
    }

    #[RunInSeparateProcess]
    public function test_rejects_workbooks_without_a_supported_header(): void
    {
        $path = $this->workbook([['Número', 'Proveedor'], ['123', 'Empresa']]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('No se encontró una columna titulada CUFE');
            (new DianKeyListParser)->parse($path);
        } finally {
            @unlink($path);
        }
    }

    /** @param list<list<string>> $rows */
    private function workbook(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'dian-list');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
