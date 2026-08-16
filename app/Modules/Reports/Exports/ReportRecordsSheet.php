<?php

namespace App\Modules\Reports\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

final class ReportRecordsSheet extends AbstractReportSheet implements FromArray, WithColumnWidths, WithEvents, WithTitle
{
    /** @var list<array{key: string, label: string, format?: string}> */
    private array $columns;

    /** @var list<array<string, mixed>> */
    private array $records;

    public function __construct(
        private readonly array $report,
        private readonly string $tenantName,
    ) {
        $this->columns = array_values($report['table']['columns'] ?? []);
        $this->records = array_values($report['table']['rows'] ?? []);
    }

    public function array(): array
    {
        $rows = [
            [(string) ($this->report['table']['title'] ?? 'Records')],
            [''],
            [''],
            array_map(fn (array $column): string => ReportExcelValue::heading($column), $this->columns),
        ];

        foreach ($this->records as $record) {
            $rows[] = array_map(
                fn (array $column): bool|float|int|string|null => ReportExcelValue::value(
                    $record[$column['key']] ?? null,
                    (string) ($column['format'] ?? 'text'),
                ),
                $this->columns,
            );
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Records';
    }

    public function columnWidths(): array
    {
        $widths = [];

        foreach ($this->columns as $index => $column) {
            $format = (string) ($column['format'] ?? 'text');
            $headingLength = mb_strlen(ReportExcelValue::heading($column));
            $sampleLength = collect($this->records)
                ->take(100)
                ->map(fn (array $row): int => mb_strlen((string) ($row[$column['key']] ?? '')))
                ->max() ?? 0;
            $base = match ($format) {
                'date' => 14,
                'datetime' => 21,
                'money' => 19,
                'basis_points', 'percent' => 14,
                'number', 'minutes' => 13,
                default => 18,
            };
            $widths[Coordinate::stringFromColumnIndex($index + 1)] = min(
                38,
                max($base, $headingLength + 2, min(36, $sampleLength + 2)),
            );
        }

        return $widths;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $this->applyBaseStyle($sheet);
                $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($this->columns)));
                $recordCount = count($this->records);
                $this->styleTitleBand(
                    $sheet,
                    $lastColumn,
                    $this->tenantName.' • '.$recordCount.' filtered record'.($recordCount === 1 ? '' : 's'),
                );
                $this->styleTableHeader($sheet, "A4:{$lastColumn}4");

                $lastRow = max(4, 4 + $recordCount);
                if ($recordCount > 0) {
                    $this->styleDataRange($sheet, "A5:{$lastColumn}{$lastRow}");

                    foreach ($this->columns as $index => $column) {
                        $letter = Coordinate::stringFromColumnIndex($index + 1);
                        $format = (string) ($column['format'] ?? 'text');
                        $sheet->getStyle("{$letter}5:{$letter}{$lastRow}")
                            ->getNumberFormat()->setFormatCode(ReportExcelValue::numberFormat($format));

                        if ($format !== 'text' && $format !== 'status') {
                            $sheet->getStyle("{$letter}5:{$letter}{$lastRow}")
                                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                        }
                    }
                }

                $sheet->setAutoFilter("A4:{$lastColumn}{$lastRow}");
                $sheet->freezePane('A5');
                $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 4);
                $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
                $sheet->getPageMargins()->setTop(0.45)->setRight(0.35)->setBottom(0.45)->setLeft(0.35);
            },
        ];
    }
}
