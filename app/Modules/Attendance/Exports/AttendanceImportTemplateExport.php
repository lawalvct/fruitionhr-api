<?php

namespace App\Modules\Attendance\Exports;

use App\Modules\Employee\Models\Employee;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class AttendanceImportTemplateExport implements WithMultipleSheets
{
    public function __construct(private readonly string $period)
    {
    }

    public function sheets(): array
    {
        $employees = Employee::query()
            ->where('employment_status', '!=', Employee::STATUS_EXITED)
            ->with('currentAssignment.department')
            ->orderBy('employee_number')
            ->get();

        return [
            $this->attendanceSheet($employees->take(3)),
            $this->employeesSheet($employees),
            $this->instructionsSheet(),
        ];
    }

    private function attendanceSheet(iterable $employees): object
    {
        $rows = [];

        foreach ($employees as $index => $employee) {
            $rows[] = [
                $employee->employee_number,
                Carbon::createFromFormat('Y-m-d', $this->period.'-01')->addDays($index)->toDateString(),
                ['08:00', '08:15', '08:05'][$index] ?? '08:00',
                '17:00',
            ];
        }

        if ($rows === []) {
            $rows = [
                ['REPLACE-WITH-EMPLOYEE-NUMBER', $this->period.'-01', '08:00', '17:00'],
            ];
        }

        return new class($rows) implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
        {
            public function __construct(private readonly array $rows)
            {
            }

            public function title(): string
            {
                return 'Attendance';
            }

            public function headings(): array
            {
                return ['employee_number', 'date', 'clock_in', 'clock_out'];
            }

            public function array(): array
            {
                return $this->rows;
            }
        };
    }

    private function employeesSheet(iterable $employees): object
    {
        $rows = [];

        foreach ($employees as $employee) {
            $rows[] = [
                $employee->employee_number,
                $employee->full_name,
                $employee->currentAssignment?->department?->name ?? '',
            ];
        }

        return new class($rows) implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
        {
            public function __construct(private readonly array $rows)
            {
            }

            public function title(): string
            {
                return 'Employees';
            }

            public function headings(): array
            {
                return ['employee_number', 'employee_name', 'department'];
            }

            public function array(): array
            {
                return $this->rows;
            }
        };
    }

    private function instructionsSheet(): object
    {
        $rows = [
            ['Required fields', 'employee_number and date'],
            ['Employee number', 'Use the exact employee_number from the Employees sheet or your employee records.'],
            ['Date', 'Use YYYY-MM-DD. The sample rows use the selected attendance period.'],
            ['Clock times', 'Use 24-hour H:i values such as 08:00 and 17:00. Leave either time blank when unavailable.'],
            ['Overnight shifts', 'For an overnight shift, clock_out may be earlier than clock_in, for example 19:00 to 07:00.'],
            ['Sample rows', 'Replace or delete the sample rows before importing if they are not actual attendance records.'],
            ['CSV imports', 'Use the Attendance sheet headings: employee_number,date,clock_in,clock_out.'],
        ];

        return new class($rows) implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
        {
            public function __construct(private readonly array $rows)
            {
            }

            public function title(): string
            {
                return 'Instructions';
            }

            public function headings(): array
            {
                return ['topic', 'guidance'];
            }

            public function array(): array
            {
                return $this->rows;
            }
        };
    }
}
