<?php

namespace App\Modules\Reports\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCharts;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

final class ReportDatasetsSheet extends AbstractReportSheet implements FromArray, WithCharts, WithColumnWidths, WithEvents, WithTitle
{
    /** @var list<string> */
    private const CHART_COLORS = ['0B7A5A', '22A06B', '84CC16', '14B8A6', '166534'];

    /**
     * @var list<array{
     *     dataset: array,
     *     title_row: int,
     *     header_row: int,
     *     data_start: int,
     *     data_end: int,
     *     data_count: int,
     *     series_count: int,
     *     last_column: string,
     *     chart_points: int
     * }>
     */
    private array $placements = [];

    private int $highestColumnIndex = 2;

    public function __construct(
        private readonly array $report,
        private readonly string $tenantName,
    ) {}

    public function array(): array
    {
        $rows = [
            ['Trends & breakdowns'],
            [''],
            [''],
        ];
        $currentRow = 4;

        foreach (($this->report['datasets'] ?? []) as $dataset) {
            $series = array_values($dataset['series'] ?? []);
            $data = array_values($dataset['data'] ?? []);
            $seriesCount = count($series);
            $lastColumn = Coordinate::stringFromColumnIndex(max(2, $seriesCount + 1));
            $this->highestColumnIndex = max($this->highestColumnIndex, $seriesCount + 1);

            while (count($rows) < $currentRow - 1) {
                $rows[] = [''];
            }

            $titleRow = $currentRow;
            $rows[] = [(string) ($dataset['title'] ?? 'Analysis')];
            $headerRow = ++$currentRow;
            $rows[] = [
                ($dataset['type'] ?? null) === 'line' ? 'Period' : 'Category',
                ...array_map(fn (array $item): string => ReportExcelValue::heading($item), $series),
            ];
            $dataStart = ++$currentRow;

            foreach ($data as $item) {
                $row = [(string) ($item[$dataset['x_key'] ?? 'label'] ?? '')];

                foreach ($series as $seriesItem) {
                    $format = (string) ($seriesItem['format'] ?? 'number');
                    $row[] = ReportExcelValue::value($item[$seriesItem['key']] ?? null, $format);
                }

                $rows[] = $row;
                $currentRow++;
            }

            $dataCount = count($data);
            if ($dataCount === 0) {
                $rows[] = ['No data available'];
                $currentRow++;
            }

            $dataEnd = $dataStart + max(1, $dataCount) - 1;
            $chartPoints = $this->meaningfulChartPoints($dataset, $data);
            $this->placements[] = [
                'dataset' => $dataset,
                'title_row' => $titleRow,
                'header_row' => $headerRow,
                'data_start' => $dataStart,
                'data_end' => $dataEnd,
                'data_count' => $dataCount,
                'series_count' => $seriesCount,
                'last_column' => $lastColumn,
                'chart_points' => $chartPoints,
            ];

            $currentRow = max($currentRow + 2, $titleRow + ($chartPoints > 0 ? 18 : 0));
        }

        if (($this->report['datasets'] ?? []) === []) {
            $rows[] = ['No trend or breakdown data is available for this report.'];
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Trends & Breakdowns';
    }

    public function columnWidths(): array
    {
        $widths = ['A' => 25];

        for ($index = 2; $index <= max(7, $this->highestColumnIndex); $index++) {
            $widths[Coordinate::stringFromColumnIndex($index)] = 17;
        }

        return $widths;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $this->applyBaseStyle($sheet);
                $this->styleTitleBand(
                    $sheet,
                    'R',
                    $this->tenantName.' • '.$this->report['title'].' • '.$this->report['year'],
                );

                foreach ($this->placements as $placement) {
                    $this->styleSectionHeader($sheet, $placement['title_row'], $placement['last_column']);
                    $this->styleTableHeader(
                        $sheet,
                        "A{$placement['header_row']}:{$placement['last_column']}{$placement['header_row']}",
                    );

                    if ($placement['data_count'] > 0) {
                        $this->styleDataRange(
                            $sheet,
                            "A{$placement['data_start']}:{$placement['last_column']}{$placement['data_end']}",
                        );

                        foreach (array_values($placement['dataset']['series'] ?? []) as $index => $series) {
                            $column = Coordinate::stringFromColumnIndex($index + 2);
                            $format = (string) ($series['format'] ?? 'number');
                            $sheet->getStyle("{$column}{$placement['data_start']}:{$column}{$placement['data_end']}")
                                ->getNumberFormat()->setFormatCode(ReportExcelValue::numberFormat($format));
                            $sheet->getStyle("{$column}{$placement['data_start']}:{$column}{$placement['data_end']}")
                                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                        }
                    } else {
                        $sheet->getStyle("A{$placement['data_start']}:{$placement['last_column']}{$placement['data_end']}")
                            ->getFont()->setItalic(true)->getColor()->setRGB(self::MUTED_TEXT);
                    }
                }

                $sheet->freezePane('A4');
                $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
                $sheet->getPageMargins()->setTop(0.45)->setRight(0.35)->setBottom(0.45)->setLeft(0.35);
            },
        ];
    }

    /** @return list<Chart> */
    public function charts(): array
    {
        $charts = [];
        $sheetName = str_replace("'", "''", $this->title());

        foreach ($this->placements as $placementIndex => $placement) {
            if ($placement['chart_points'] === 0 || $placement['series_count'] === 0) {
                continue;
            }

            $dataset = $placement['dataset'];
            $chartEnd = $placement['data_start'] + $placement['chart_points'] - 1;
            $labels = [];
            $values = [];

            foreach (array_values($dataset['series'] ?? []) as $index => $series) {
                $column = Coordinate::stringFromColumnIndex($index + 2);
                $labels[] = new DataSeriesValues(
                    DataSeriesValues::DATASERIES_TYPE_STRING,
                    "'{$sheetName}'!\${$column}\${$placement['header_row']}",
                    null,
                    1,
                );
                $seriesValues = new DataSeriesValues(
                    DataSeriesValues::DATASERIES_TYPE_NUMBER,
                    "'{$sheetName}'!\${$column}\${$placement['data_start']}:\${$column}\${$chartEnd}",
                    ReportExcelValue::numberFormat((string) ($series['format'] ?? 'number')),
                    $placement['chart_points'],
                );
                $seriesValues->setFillColor(
                    ($dataset['type'] ?? null) === 'donut'
                        ? array_map(
                            fn (int $point): string => self::CHART_COLORS[$point % count(self::CHART_COLORS)],
                            range(0, $placement['chart_points'] - 1),
                        )
                        : self::CHART_COLORS[$index % count(self::CHART_COLORS)],
                );
                $values[] = $seriesValues;
            }

            $categories = [new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                "'{$sheetName}'!\$A\${$placement['data_start']}:\$A\${$chartEnd}",
                null,
                $placement['chart_points'],
            )];
            $type = match ($dataset['type'] ?? 'bar') {
                'line' => DataSeries::TYPE_LINECHART,
                'donut' => DataSeries::TYPE_DONUTCHART,
                default => DataSeries::TYPE_BARCHART,
            };
            $grouping = ($dataset['type'] ?? null) === 'bar'
                ? DataSeries::GROUPING_CLUSTERED
                : DataSeries::GROUPING_STANDARD;
            $direction = ($dataset['type'] ?? null) === 'bar'
                ? DataSeries::DIRECTION_COL
                : null;
            $series = new DataSeries(
                $type,
                $grouping,
                range(0, count($values) - 1),
                $labels,
                $categories,
                $values,
                $direction,
            );
            $plotArea = new PlotArea(null, [$series]);
            $legend = count($values) > 1 || ($dataset['type'] ?? null) === 'donut'
                ? new Legend(Legend::POSITION_RIGHT, null, false)
                : null;
            $chart = new Chart(
                'report_chart_'.$placementIndex,
                new Title((string) ($dataset['title'] ?? 'Analysis')),
                $legend,
                $plotArea,
                true,
                DataSeries::EMPTY_AS_GAP,
            );
            $chart->setTopLeftPosition('J'.$placement['title_row']);
            $chart->setBottomRightPosition('R'.($placement['title_row'] + 15));
            $charts[] = $chart;
        }

        return $charts;
    }

    private function meaningfulChartPoints(array $dataset, array $data): int
    {
        $count = count($data);

        if ($count < 2) {
            return 0;
        }

        $series = array_values($dataset['series'] ?? []);
        $hasValue = collect($data)->contains(function (array $row) use ($series): bool {
            foreach ($series as $item) {
                $value = $row[$item['key']] ?? null;

                if (is_numeric($value) && (float) $value !== 0.0) {
                    return true;
                }
            }

            return false;
        });

        if (! $hasValue) {
            return 0;
        }

        return min($count, ($dataset['type'] ?? null) === 'donut' ? 8 : 12);
    }
}
