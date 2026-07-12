<?php

namespace App\Modules\Employee\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class EmployeesExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private readonly Collection $employees)
    {
    }

    public function collection(): Collection
    {
        return $this->employees;
    }

    public function headings(): array
    {
        return [
            'Employee number',
            'First name',
            'Middle name',
            'Last name',
            'Official email',
            'Personal email',
            'Phone',
            'Gender',
            'Marital status',
            'Date of birth',
            'Country',
            'State',
            'City',
            'Department',
            'Position',
            'Employment status',
            'Hire date',
        ];
    }

    public function map($employee): array
    {
        return [
            $employee->employee_number,
            $employee->first_name,
            $employee->middle_name,
            $employee->last_name,
            $employee->official_email,
            $employee->personal_email,
            $employee->phone,
            $employee->gender,
            $employee->marital_status,
            $employee->date_of_birth?->format('Y-m-d'),
            $employee->country,
            $employee->state,
            $employee->city,
            $employee->currentAssignment?->department?->name,
            $employee->currentAssignment?->position?->title,
            str_replace('_', ' ', $employee->employment_status),
            $employee->hired_at?->format('Y-m-d'),
        ];
    }
}
