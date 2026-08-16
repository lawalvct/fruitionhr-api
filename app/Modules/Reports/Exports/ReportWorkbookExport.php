<?php

namespace App\Modules\Reports\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithProperties;

final class ReportWorkbookExport implements WithMultipleSheets, WithProperties
{
    public function __construct(
        private readonly array $report,
        private readonly string $tenantName,
    ) {}

    public function sheets(): array
    {
        return [
            new ReportSummarySheet($this->report, $this->tenantName),
            new ReportDatasetsSheet($this->report, $this->tenantName),
            new ReportRecordsSheet($this->report, $this->tenantName),
        ];
    }

    public function properties(): array
    {
        $title = $this->report['title'].' - '.$this->report['year'];

        return [
            'creator' => 'FruitionHR',
            'lastModifiedBy' => 'FruitionHR',
            'title' => $title,
            'subject' => $this->report['title'].' for '.$this->tenantName,
            'description' => 'Detailed HR analysis workbook with summary KPIs, trends, breakdowns, and complete filtered records.',
            'keywords' => 'FruitionHR, HR analytics, '.$this->report['module'].', '.$this->report['year'],
            'category' => 'HR Analytics',
            'company' => str_replace("\0", '', $this->tenantName),
        ];
    }
}
