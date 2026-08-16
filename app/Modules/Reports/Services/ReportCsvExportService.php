<?php

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Exports\SafeCsvExport;
use Generator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportCsvExportService
{
    public function __construct(
        private readonly ReportAnalysisService $analysis,
        private readonly SafeCsvExport $csv,
    ) {}

    public function download(string $module, int $year, array $filters): StreamedResponse
    {
        // CSV contains every record matching the active analysis filters. The
        // interactive table remains capped independently for browser performance.
        $report = $this->analysis->build($module, $year, $filters, null);
        $table = $report['table'];
        $columns = $table['columns'];
        $filename = sprintf('%s-report-%d.csv', Str::slug($module), $year);

        return $this->csv->download(
            array_map($this->heading(...), $columns),
            $this->rows($columns, $table['rows']),
            $filename,
        );
    }

    private function heading(array $column): string
    {
        $label = (string) $column['label'];

        return match ($column['format'] ?? 'text') {
            'money' => $label.' (kobo)',
            'basis_points' => $label.' (basis points)',
            'minutes' => $label.' (minutes)',
            'percent' => $label.' (%)',
            default => $label,
        };
    }

    /**
     * @param  list<array{key: string, label: string, format?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return Generator<int, list<bool|float|int|string|null>>
     */
    private function rows(array $columns, array $rows): Generator
    {
        foreach ($rows as $row) {
            yield array_map(
                fn (array $column): bool|float|int|string|null => $this->cell($row[$column['key']] ?? null),
                $columns,
            );
        }
    }

    private function cell(mixed $value): bool|float|int|string|null
    {
        if ($value === null || is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
