<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Company\Models\Branch;
use App\Modules\Company\Models\Department;
use App\Modules\Company\Models\EmploymentType;
use App\Modules\Company\Models\HolidayCalendar;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Payroll\Models\SalaryComponent;

class StarterDataProvisioner
{
    public function provision(User $owner, array $data = []): void
    {
        $branch = Branch::query()->firstOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'Main Office',
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'created_by' => $owner->id,
            ],
        );

        foreach ([
            ['Administration', 'ADMIN'],
            ['Finance', 'FIN'],
            ['Operations', 'OPS'],
            ['Sales', 'SALES'],
        ] as [$name, $code]) {
            Department::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'branch_id' => $branch->id, 'created_by' => $owner->id],
            );
        }

        foreach (['Full-time', 'Part-time', 'Contract', 'Intern'] as $name) {
            EmploymentType::query()->firstOrCreate(
                ['name' => $name],
                ['created_by' => $owner->id],
            );
        }

        foreach ([
            ['Annual Leave', 'ANNUAL', true, false],
            ['Sick Leave', 'SICK', true, true],
            ['Maternity Leave', 'MATERNITY', true, false],
            ['Paternity Leave', 'PATERNITY', true, false],
        ] as [$name, $code, $isPaid, $requiresDocument]) {
            LeaveType::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'is_paid' => $isPaid,
                    'requires_document' => $requiresDocument,
                    'created_by' => $owner->id,
                ],
            );
        }

        foreach ([
            ['Basic Salary', 'BASIC', true, true],
            ['Housing Allowance', 'HOUSING', true, true],
            ['Transport Allowance', 'TRANSPORT', true, true],
            ['Other Allowance', 'OTHER', true, false],
        ] as [$name, $code, $isTaxable, $isPensionable]) {
            SalaryComponent::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => SalaryComponent::TYPE_EARNING,
                    'calc_type' => SalaryComponent::CALC_FIXED,
                    'is_taxable' => $isTaxable,
                    'is_pensionable' => $isPensionable,
                    'created_by' => $owner->id,
                ],
            );
        }

        $country = $data['country'] ?? 'Nigeria';

        HolidayCalendar::query()->firstOrCreate(
            ['year' => (int) now()->year, 'name' => $country.' Public Holidays'],
            ['created_by' => $owner->id],
        );
    }
}
