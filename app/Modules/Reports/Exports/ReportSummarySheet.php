<?php

namespace App\Modules\Reports\Exports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

final class ReportSummarySheet extends AbstractReportSheet implements FromArray, WithColumnWidths, WithEvents, WithTitle
{
    private int $filtersHeaderRow;

    private int $kpiSectionRow;

    private int $kpiHeaderRow;

    /** @var array<int, string> */
    private array $kpiFormats = [];

    public function __construct(
        private readonly array $report,
        private readonly string $tenantName,
    ) {}

    public function array(): array
    {
        $rows = [
            [(string) $this->report['title']],
            [''],
            [''],
            ['Report details'],
            ['Organization', $this->tenantName],
            ['Report', (string) $this->report['title']],
            ['Reporting year', (int) $this->report['year']],
            [
                'Generated at',
                ExcelDate::dateTimeToExcel(Carbon::parse($this->report['generated_at'] ?? now())),
            ],
            ['Currency', 'Nigerian naira (NGN); money converted from source kobo values'],
            [''],
        ];

        $this->filtersHeaderRow = count($rows) + 1;
        $rows[] = ['Applied filters'];

        foreach (($this->report['filters']['applied'] ?? []) as $key => $value) {
            $rows[] = [
                $this->filterName((string) $key),
                $this->filterValue((string) $key, $value),
            ];
        }

        if (($this->report['filters']['applied'] ?? []) === []) {
            $rows[] = ['Scope', 'All records'];
        }

        $rows[] = [''];
        $this->kpiSectionRow = count($rows) + 1;
        $rows[] = ['Key performance indicators'];
        $this->kpiHeaderRow = count($rows) + 1;
        $rows[] = ['Metric', 'Value', 'Unit'];

        foreach (($this->report['metrics'] ?? []) as $metric) {
            $format = (string) ($metric['format'] ?? 'number');
            $row = count($rows) + 1;
            $this->kpiFormats[$row] = $format;
            $rows[] = [
                (string) $metric['label'],
                ReportExcelValue::value($metric['value'] ?? null, $format),
                ReportExcelValue::unit($format),
            ];
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Summary';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 30,
            'B' => 32,
            'C' => 16,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $this->applyBaseStyle($sheet);
                $this->styleTitleBand(
                    $sheet,
                    'C',
                    $this->tenantName.' • Reporting year '.$this->report['year'],
                );
                $this->styleSectionHeader($sheet, 4, 'C');
                $this->styleSectionHeader($sheet, $this->filtersHeaderRow, 'C');
                $this->styleSectionHeader($sheet, $this->kpiSectionRow, 'C');
                $this->styleTableHeader($sheet, "A{$this->kpiHeaderRow}:C{$this->kpiHeaderRow}");

                $sheet->getStyle('A5:A9')->getFont()->setBold(true)->getColor()->setRGB(self::MUTED_TEXT);
                $sheet->getStyle('A'.($this->filtersHeaderRow + 1).':A'.($this->kpiSectionRow - 2))
                    ->getFont()->setBold(true)->getColor()->setRGB(self::MUTED_TEXT);
                $sheet->getStyle('B5:B'.($this->kpiHeaderRow - 2))->getAlignment()
                    ->setWrapText(true);
                $sheet->getStyle('B7')->getNumberFormat()->setFormatCode('0');
                $sheet->getStyle('B8')->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');

                foreach ($this->kpiFormats as $row => $format) {
                    $sheet->getStyle("B{$row}")->getNumberFormat()
                        ->setFormatCode(ReportExcelValue::numberFormat($format));
                }

                $lastRow = $sheet->getHighestDataRow();
                if ($lastRow > $this->kpiHeaderRow) {
                    $this->styleDataRange($sheet, 'A'.($this->kpiHeaderRow + 1).":C{$lastRow}");
                    $sheet->getStyle('B'.($this->kpiHeaderRow + 1).":B{$lastRow}")
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    $sheet->getStyle('C'.($this->kpiHeaderRow + 1).":C{$lastRow}")
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                $sheet->freezePane('A5');
                $sheet->getPageSetup()->setOrientation('portrait')->setFitToWidth(1)->setFitToHeight(0);
                $sheet->getPageMargins()->setTop(0.45)->setRight(0.35)->setBottom(0.45)->setLeft(0.35);
            },
        ];
    }

    private function filterName(string $key): string
    {
        return (string) Str::of($key)
            ->replace('_id', '')
            ->replace('_', ' ')
            ->headline();
    }

    private function filterValue(string $key, mixed $value): string|int
    {
        if ($value === null || $value === '') {
            return 'All';
        }

        $availableKey = match ($key) {
            'department_id' => 'departments',
            'period' => 'periods',
            'status' => 'statuses',
            'stage' => 'stages',
            default => null,
        };

        if ($availableKey !== null) {
            foreach (($this->report['filters']['available'][$availableKey] ?? []) as $option) {
                if ((string) ($option['value'] ?? '') === (string) $value) {
                    return (string) ($option['label'] ?? $value);
                }
            }
        }

        return is_int($value) ? $value : (string) $value;
    }
}
