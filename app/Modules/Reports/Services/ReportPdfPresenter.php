<?php

namespace App\Modules\Reports\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

final class ReportPdfPresenter
{
    /** @var list<string> */
    private const COLOURS = ['#07845c', '#2563eb', '#d97706', '#7c3aed', '#e11d48', '#0891b2'];

    public function present(array $report): array
    {
        return [
            'module' => (string) $report['module'],
            'title' => (string) $report['title'],
            'year' => (int) $report['year'],
            'generated_at' => $this->dateTime($report['generated_at'] ?? null),
            'filters' => $this->filters($report['filters'] ?? []),
            'metrics' => array_map(fn (array $metric): array => [
                'label' => (string) $metric['label'],
                'value' => $this->format($metric['value'] ?? null, (string) ($metric['format'] ?? 'number')),
            ], $report['metrics'] ?? []),
            'datasets' => array_map($this->dataset(...), $report['datasets'] ?? []),
            'table' => $this->table($report['table'] ?? []),
        ];
    }

    private function filters(array $filters): array
    {
        $available = $filters['available'] ?? [];
        $applied = $filters['applied'] ?? [];
        $result = [];

        foreach ($applied as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $result[] = [
                'label' => match ($key) {
                    'department_id' => 'Department',
                    'period' => 'Period',
                    'status' => 'Status',
                    'stage' => 'Stage',
                    default => Str::headline((string) $key),
                },
                'value' => $this->filterValue((string) $key, $value, $available),
            ];
        }

        return $result;
    }

    private function filterValue(string $key, mixed $value, array $available): string
    {
        $optionsKey = match ($key) {
            'department_id' => 'departments',
            'period' => 'periods',
            'status' => 'statuses',
            'stage' => 'stages',
            default => null,
        };

        if ($optionsKey !== null) {
            foreach ($available[$optionsKey] ?? [] as $option) {
                if ((string) ($option['value'] ?? '') === (string) $value) {
                    return (string) ($option['label'] ?? $value);
                }
            }
        }

        return Str::headline((string) $value);
    }

    private function dataset(array $dataset): array
    {
        $series = $dataset['series'] ?? [];
        $data = $dataset['data'] ?? [];
        $displayLimit = ($dataset['type'] ?? null) === 'line' ? 12 : 10;
        $displayData = array_slice($data, 0, $displayLimit);
        $maximum = 0.0;

        foreach ($displayData as $row) {
            foreach ($series as $item) {
                $maximum = max($maximum, abs((float) ($row[$item['key']] ?? 0)));
            }
        }

        return [
            'title' => (string) ($dataset['title'] ?? 'Analysis'),
            'type' => (string) ($dataset['type'] ?? 'bar'),
            'series' => array_map(fn (array $item, int $index): array => [
                'key' => (string) $item['key'],
                'label' => (string) $item['label'],
                'format' => (string) ($item['format'] ?? 'number'),
                'colour' => self::COLOURS[$index % count(self::COLOURS)],
            ], $series, array_keys($series)),
            'rows' => array_map(function (array $row) use ($dataset, $series, $maximum): array {
                return [
                    'label' => $this->format($row[$dataset['x_key']] ?? null, 'text'),
                    'values' => array_map(fn (array $item, int $index): array => [
                        'display' => $this->format($row[$item['key']] ?? null, (string) ($item['format'] ?? 'number')),
                        'width' => $maximum > 0
                            ? max(0.0, min(100.0, abs((float) ($row[$item['key']] ?? 0)) / $maximum * 100))
                            : 0.0,
                        'colour' => self::COLOURS[$index % count(self::COLOURS)],
                    ], $series, array_keys($series)),
                ];
            }, $displayData),
            'total_points' => count($data),
            'limited' => count($data) > count($displayData),
        ];
    }

    private function table(array $table): array
    {
        $columns = $table['columns'] ?? [];

        return [
            'title' => (string) ($table['title'] ?? 'Underlying records'),
            'columns' => array_map(fn (array $column): array => [
                'key' => (string) $column['key'],
                'label' => (string) $column['label'],
                'format' => (string) ($column['format'] ?? 'text'),
            ], $columns),
            'rows' => array_map(fn (array $row): array => array_map(
                fn (array $column): string => $this->format(
                    $row[$column['key']] ?? null,
                    (string) ($column['format'] ?? 'text'),
                ),
                $columns,
            ), $table['rows'] ?? []),
            'meta' => [
                'count' => (int) ($table['meta']['count'] ?? 0),
                'limit' => ($table['meta']['limit'] ?? null) === null ? null : (int) $table['meta']['limit'],
                'limited' => (bool) ($table['meta']['limited'] ?? false),
            ],
        ];
    }

    private function format(mixed $value, string $format): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return match ($format) {
            'money' => 'NGN '.number_format(((float) $value) / 100, 2),
            'number' => $this->number($value),
            'percent' => $this->number($value).'%',
            'basis_points' => $this->number(((float) $value) / 100).'%',
            'minutes' => $this->minutes((int) $value),
            'date' => $this->date($value),
            'datetime' => $this->dateTime($value),
            'status' => Str::headline((string) $value),
            default => is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value,
        };
    }

    private function number(mixed $value): string
    {
        $numeric = (float) $value;

        return number_format($numeric, fmod(abs($numeric), 1.0) === 0.0 ? 0 : 2);
    }

    private function minutes(int $minutes): string
    {
        $hours = intdiv(abs($minutes), 60);
        $remainder = abs($minutes) % 60;
        $prefix = $minutes < 0 ? '-' : '';

        return $hours === 0
            ? $prefix.$remainder.' min'
            : sprintf('%s%d hr%s %d min', $prefix, $hours, $hours === 1 ? '' : 's', $remainder);
    }

    private function date(mixed $value): string
    {
        try {
            return Carbon::parse($value)->format('d M Y');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function dateTime(mixed $value): string
    {
        try {
            return Carbon::parse($value)->format('d M Y, H:i');
        } catch (Throwable) {
            return (string) ($value ?? '-');
        }
    }
}
