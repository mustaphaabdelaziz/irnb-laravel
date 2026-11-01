<?php

namespace App\Reports;

use App\Services\Pdf\ClubHeader;
use App\Services\Pdf\PdfService;
use App\Support\Export;
use App\Support\UiLang;
use Symfony\Component\HttpFoundation\Response;

/**
 * A report as a file: .xlsx / .csv through the app's one Export path, or a
 * PDF through PdfService with the club header. Same filters, sort and
 * permission as the screen; every filtered row, not one page.
 */
final class ReportExporter
{
    /**
     * Past this many rows the PDF stops and says so; the spreadsheet has no
     * cap. mPDF takes ~10 ms a line here (835 lines ≈ 10 s on real data).
     */
    public const PDF_MAX_ROWS = 1000;

    public function __construct(
        private readonly ReportRunner $runner,
        private readonly PdfService $pdf,
    ) {}

    public function download(Report $report, ReportFilters $filters, string $format): Response
    {
        $columns = $report->columns();
        $headers = array_map(fn (Column $c) => UiLang::get($c->label), $columns);
        $title = UiLang::get($report->title());
        $filename = 'report-'.$report->category().'-'.$report->key().'-'.now()->format('Y-m-d');
        $rows = $this->runner->all($report, $filters);

        if ($format === 'pdf') {
            return $this->pdf($report, $filters, $columns, $headers, $title, $rows, $filename);
        }

        $cells = (function () use ($rows, $columns) {
            foreach ($rows as $row) {
                yield array_map(fn (Column $c) => $c->cell($row[$c->key] ?? null), $columns);
            }
        })();

        return Export::download($format === Export::CSV ? Export::CSV : Export::XLSX, $filename, $headers, $cells, $title);
    }

    /**
     * @param  list<Column>  $columns
     * @param  list<string>  $headers
     * @param  iterable<array<string, mixed>>  $rows
     */
    private function pdf(Report $report, ReportFilters $filters, array $columns, array $headers, string $title, iterable $rows, string $filename): Response
    {
        $club = ClubHeader::data();
        $printed = [];
        $truncated = false;
        foreach ($rows as $row) {
            if (count($printed) === self::PDF_MAX_ROWS) {
                $truncated = true;
                break;
            }
            $printed[] = array_map(fn (Column $c) => $c->display($row[$c->key] ?? null, $club['currency']), $columns);
        }

        $totals = $report->totals($filters);
        $html = view('pdf.report', [
            'club' => $club,
            'title' => $title,
            'applied' => $filters->describe(),
            'summary' => array_map(fn (array $tile) => [
                'label' => UiLang::get($tile['label']),
                'value' => (new Column('', '', in_array($tile['format'], [Column::MONEY, Column::PERCENT, Column::NUMBER], true) ? $tile['format'] : Column::TEXT))
                    ->display($tile['value'], $club['currency']),
            ], $report->summary($filters)),
            'columns' => $columns,
            'headers' => $headers,
            'rows' => $printed,
            'totals' => $totals === [] ? null : array_map(fn (Column $c) => $c->total ? $c->display($totals[$c->key] ?? null, $club['currency']) : '', $columns),
            'truncated' => $truncated,
            'generatedAt' => now()->format('Y-m-d H:i'),
            'generatedBy' => auth()->user()?->name,
            'labels' => [
                'generated' => UiLang::get('reports.generated_at'),
                'total' => UiLang::get('reports.total'),
                'truncated' => UiLang::get('reports.pdf_truncated'),
                'page' => UiLang::get('reports.page'),
            ],
        ])->render();

        return $this->pdf->stream($html, $filename.'.pdf', app()->getLocale() === 'ar', $report->landscape());
    }
}
