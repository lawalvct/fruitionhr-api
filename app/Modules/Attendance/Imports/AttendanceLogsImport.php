<?php

namespace App\Modules\Attendance\Imports;

use App\Modules\Attendance\Models\AttendanceLog;
use App\Modules\Employee\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports attendance logs from xlsx/csv. Expected headings:
 *   employee_number | date (Y-m-d) | clock_in (H:i) | clock_out (H:i)
 *
 * Runs inside tenant context (called from a tenant-scoped request), so
 * BelongsToTenant fills tenant_id and Employee lookups are tenant-scoped.
 */
class AttendanceLogsImport implements ToCollection, WithHeadingRow
{
    public int $imported = 0;

    public int $skipped = 0;

    /** @var list<string> */
    public array $errors = [];

    public function __construct(private readonly int $userId)
    {
    }

    public function collection(Collection $rows): void
    {
        // Cache employee numbers → ids (tenant-scoped) to avoid N queries.
        $employees = Employee::query()->pluck('id', 'employee_number');

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // heading row + 1-based
            $number = trim((string) ($row['employee_number'] ?? ''));
            $date = $this->normalizeDate($row['date'] ?? null);

            if ($number === '' || $date === null) {
                $this->skipped++;
                $this->errors[] = "Row {$rowNumber}: missing employee_number or date.";

                continue;
            }

            $employeeId = $employees->get($number);
            if ($employeeId === null) {
                $this->skipped++;
                $this->errors[] = "Row {$rowNumber}: unknown employee_number '{$number}'.";

                continue;
            }

            AttendanceLog::query()->updateOrCreate(
                ['employee_id' => $employeeId, 'date' => $date],
                [
                    'clock_in' => $this->normalizeTime($row['clock_in'] ?? null),
                    'clock_out' => $this->normalizeTime($row['clock_out'] ?? null),
                    'source' => AttendanceLog::SOURCE_IMPORT,
                    'created_by' => $this->userId,
                ],
            );

            $this->imported++;
        }
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // Handle Excel serial dates and common string formats.
            if (is_numeric($value)) {
                return Carbon::createFromTimestamp(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float) $value)
                )->toDateString();
            }

            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                // Excel time fraction of a day.
                $minutes = (int) round(((float) $value) * 24 * 60);

                return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            }

            return Carbon::parse((string) $value)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }
}
