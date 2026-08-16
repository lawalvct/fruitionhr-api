<?php

namespace App\Modules\Reports\Exports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

final class ReportExcelValue
{
    public static function safeText(string $value): string
    {
        $value = str_replace("\0", '', $value);

        if (preg_match('/^(?:[=+\-@\t\r\n]|[\p{Z}\x00-\x20]+[=+\-@])/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    public static function heading(array $column): string
    {
        $label = (string) ($column['label'] ?? Str::headline((string) ($column['key'] ?? 'Value')));

        return match ($column['format'] ?? 'text') {
            'money' => $label.' (NGN)',
            'basis_points', 'percent' => $label.' (%)',
            'minutes' => $label.' (minutes)',
            default => $label,
        };
    }

    public static function value(mixed $value, string $format = 'text'): bool|float|int|string|null
    {
        if ($value === null) {
            return null;
        }

        return match ($format) {
            'money' => is_numeric($value) ? ((float) $value) / 100 : null,
            'basis_points' => is_numeric($value) ? ((float) $value) / 10000 : null,
            'percent' => is_numeric($value) ? ((float) $value) / 100 : null,
            'number', 'minutes' => self::number($value),
            'date' => self::excelDate($value, false),
            'datetime' => self::excelDate($value, true),
            default => self::text($value),
        };
    }

    public static function numberFormat(string $format): string
    {
        return match ($format) {
            'money' => '"NGN" #,##0.00',
            'basis_points', 'percent' => '0.0%',
            'minutes' => '#,##0 "min"',
            'number' => '#,##0',
            'date' => 'yyyy-mm-dd',
            'datetime' => 'yyyy-mm-dd hh:mm',
            default => '@',
        };
    }

    public static function unit(string $format): string
    {
        return match ($format) {
            'money' => 'NGN',
            'basis_points', 'percent' => '%',
            'minutes' => 'minutes',
            default => 'count',
        };
    }

    private static function number(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }

    private static function excelDate(mixed $value, bool $withTime): float|string|null
    {
        try {
            $date = Carbon::parse($value);

            if (! $withTime) {
                $date = $date->startOfDay();
            }

            return ExcelDate::dateTimeToExcel($date);
        } catch (Throwable) {
            return self::text($value);
        }
    }

    private static function text(mixed $value): bool|float|int|string|null
    {
        if (is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
