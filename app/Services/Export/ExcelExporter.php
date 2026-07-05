<?php

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Small reusable helper that turns a title + headers + rows into a styled,
 * downloadable .xlsx file — so each export controller stays a few lines.
 */
class ExcelExporter
{
    /**
     * @param  list<string>  $headers
     * @param  list<array<int, mixed>>  $rows
     */
    public function download(string $title, array $headers, array $rows, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($title, 0, 28));

        $lastCol = chr(65 + max(0, count($headers) - 1));

        // Title row.
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF1E40AF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        // Header row.
        foreach ($headers as $i => $header) {
            $sheet->setCellValue(chr(65 + $i).'3', $header);
            $sheet->getColumnDimension(chr(65 + $i))->setWidth(20);
        }
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E40AF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Data rows.
        $r = 4;
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $value) {
                $sheet->setCellValue(chr(65 + $i).$r, $value);
            }
            $r++;
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
