<?php

namespace App\Modules\Employee\Exports;

use App\Modules\Company\Models\Branch;
use App\Modules\Company\Models\Department;
use App\Modules\Company\Models\EmploymentType;
use App\Modules\Company\Models\JobGrade;
use App\Modules\Company\Models\Position;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class EmployeeImportTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            $this->employeesSheet(),
            $this->referenceSheet(),
            $this->instructionsSheet(),
        ];
    }

    private function employeesSheet(): object
    {
        $positions = Position::query()->with(['department.branch', 'jobGrade'])->limit(3)->get();
        $employmentType = EmploymentType::query()->first();
        $rows = [];

        for ($index = 0; $index < 3; $index++) {
            $position = $positions->get($index) ?? $positions->first();
            $department = $position?->department;
            $branch = $department?->branch;
            $rows[] = [
                'SAMPLE-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                ['Ada', 'Tola', 'Musa'][$index],
                $index === 0 ? 'Ifeoma' : '',
                ['Okafor', 'Adeyemi', 'Bello'][$index],
                'sample'.($index + 1).'@example.test',
                '',
                '+23480000000'.($index + 1),
                $index === 2 ? 'male' : 'female',
                ['1992-04-18', '1995-08-03', '1990-11-22'][$index],
                $index === 1 ? 'married' : 'single',
                'Nigeria',
                'Lagos',
                'Lagos',
                'Replace this sample address',
                'active',
                '2026-01-'.str_pad((string) ($index + 10), 2, '0', STR_PAD_LEFT),
                $branch?->name ?? 'Use a branch from Reference Data',
                $department?->name ?? 'Use a department from Reference Data',
                $position?->title ?? 'Use a position from Reference Data',
                $position?->jobGrade?->name ?? (JobGrade::query()->value('name') ?? ''),
                $employmentType?->name ?? '',
            ];
        }

        return new class($rows) implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
        {
            public function __construct(private readonly array $rows) {}
            public function title(): string { return 'Employees'; }
            public function headings(): array { return ['employee_number', 'first_name', 'middle_name', 'last_name', 'official_email', 'personal_email', 'phone', 'gender', 'date_of_birth', 'marital_status', 'country', 'state', 'city', 'address', 'employment_status', 'hired_at', 'branch', 'department', 'position', 'job_grade', 'employment_type']; }
            public function array(): array { return $this->rows; }
        };
    }

    private function referenceSheet(): object
    {
        $rows = [];
        foreach (Branch::query()->orderBy('name')->get() as $branch) $rows[] = ['Branch', $branch->name, $branch->code, ''];
        foreach (Department::query()->with('branch')->orderBy('name')->get() as $department) $rows[] = ['Department', $department->name, $department->code, 'Branch: '.($department->branch?->name ?? 'None')];
        foreach (Position::query()->with('department')->orderBy('title')->get() as $position) $rows[] = ['Position', $position->title, $position->code, 'Department: '.($position->department?->name ?? 'None')];
        foreach (JobGrade::query()->orderBy('level')->get() as $grade) $rows[] = ['Job grade', $grade->name, $grade->code, ''];
        foreach (EmploymentType::query()->orderBy('name')->get() as $type) $rows[] = ['Employment type', $type->name, '', ''];

        return new class($rows) implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
        {
            public function __construct(private readonly array $rows) {}
            public function title(): string { return 'Reference Data'; }
            public function headings(): array { return ['resource', 'accepted_name', 'accepted_code', 'depends_on']; }
            public function array(): array { return $this->rows; }
        };
    }

    private function instructionsSheet(): object
    {
        $rows = [
            ['Required fields', 'first_name, last_name, hired_at'],
            ['Sample rows', 'The three sample rows are valid and will be imported if left in the Employees sheet. Replace or delete them if you do not want sample employees.'],
            ['Branch', 'Use an accepted branch name or code from Reference Data.'],
            ['Department', 'Use an accepted department name or code. The department should belong to the selected branch.'],
            ['Position', 'Use an accepted position name or code. The position must belong to the selected department.'],
            ['Job grade', 'Use an accepted job grade name or code from Reference Data.'],
            ['Employment type', 'Use an accepted employment type name from Reference Data.'],
            ['Employment status', 'Accepted values: active, on_leave, suspended, exited.'],
            ['Dates', 'Use YYYY-MM-DD or a valid Excel date.'],
            ['CSV imports', 'Use the same headings shown on the Employees sheet.'],
        ];

        return new class($rows) implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
        {
            public function __construct(private readonly array $rows) {}
            public function title(): string { return 'Instructions'; }
            public function headings(): array { return ['topic', 'guidance']; }
            public function array(): array { return $this->rows; }
        };
    }
}
