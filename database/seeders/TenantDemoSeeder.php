<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Company\Models\Branch;
use App\Modules\Company\Models\Department;
use App\Modules\Company\Models\EmploymentType;
use App\Modules\Company\Models\HolidayCalendar;
use App\Modules\Company\Models\JobGrade;
use App\Modules\Company\Models\Position;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'demo-company'],
            [
                'name' => 'Demo Company',
                'email' => 'hr@demo-company.test',
                'phone' => '+2348000000000',
                'status' => Tenant::STATUS_ACTIVE,
            ],
        );

        app(TenantRoleProvisioner::class)->provision($tenant);
        app(CurrentTenant::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $owner = User::query()->firstOrCreate(
            ['email' => 'owner@demo-company.test'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Demo Owner',
                'password' => Hash::make('password'),
                'status' => User::STATUS_ACTIVE,
            ],
        );

        if (! $owner->hasRole('owner')) {
            $owner->assignRole('owner');
        }

        $branches = collect([
            ['name' => 'Lagos HQ', 'code' => 'LAG-HQ', 'city' => 'Lagos', 'state' => 'Lagos'],
            ['name' => 'Abuja Office', 'code' => 'ABJ', 'city' => 'Abuja', 'state' => 'FCT'],
        ])->map(fn (array $data) => Branch::query()->firstOrCreate(
            ['code' => $data['code']],
            [...$data, 'address' => $data['city'].' business district', 'created_by' => $owner->id],
        ));

        $departments = collect([
            ['name' => 'People Operations', 'code' => 'HR', 'branch_id' => $branches[0]->id],
            ['name' => 'Finance', 'code' => 'FIN', 'branch_id' => $branches[0]->id],
            ['name' => 'Sales', 'code' => 'SAL', 'branch_id' => $branches[1]->id],
            ['name' => 'Engineering', 'code' => 'ENG', 'branch_id' => $branches[0]->id],
            ['name' => 'Customer Success', 'code' => 'CS', 'branch_id' => $branches[1]->id],
        ])->map(fn (array $data) => Department::query()->firstOrCreate(
            ['code' => $data['code']],
            [...$data, 'created_by' => $owner->id],
        ));

        $grades = collect([
            ['name' => 'Associate', 'code' => 'G1', 'level' => 1, 'min_salary' => 15000000, 'max_salary' => 30000000],
            ['name' => 'Professional', 'code' => 'G2', 'level' => 2, 'min_salary' => 30000000, 'max_salary' => 60000000],
            ['name' => 'Manager', 'code' => 'G3', 'level' => 3, 'min_salary' => 60000000, 'max_salary' => 120000000],
        ])->map(fn (array $data) => JobGrade::query()->firstOrCreate(
            ['code' => $data['code']],
            [...$data, 'created_by' => $owner->id],
        ));

        foreach (['Full-time', 'Contract', 'Intern'] as $name) {
            EmploymentType::query()->firstOrCreate(['name' => $name], ['created_by' => $owner->id]);
        }

        collect([
            ['title' => 'HR Manager', 'code' => 'POS-HRM', 'department_id' => $departments[0]->id, 'job_grade_id' => $grades[2]->id],
            ['title' => 'Payroll Officer', 'code' => 'POS-PAY', 'department_id' => $departments[0]->id, 'job_grade_id' => $grades[1]->id],
            ['title' => 'Finance Manager', 'code' => 'POS-FINM', 'department_id' => $departments[1]->id, 'job_grade_id' => $grades[2]->id],
            ['title' => 'Accountant', 'code' => 'POS-ACC', 'department_id' => $departments[1]->id, 'job_grade_id' => $grades[1]->id],
            ['title' => 'Sales Executive', 'code' => 'POS-SALES', 'department_id' => $departments[2]->id, 'job_grade_id' => $grades[0]->id],
            ['title' => 'Software Engineer', 'code' => 'POS-SWE', 'department_id' => $departments[3]->id, 'job_grade_id' => $grades[1]->id],
            ['title' => 'Engineering Lead', 'code' => 'POS-LEAD', 'department_id' => $departments[3]->id, 'job_grade_id' => $grades[2]->id],
            ['title' => 'Customer Success Associate', 'code' => 'POS-CSA', 'department_id' => $departments[4]->id, 'job_grade_id' => $grades[0]->id],
        ])->each(fn (array $data) => Position::query()->firstOrCreate(
            ['code' => $data['code']],
            [...$data, 'description' => Str::headline($data['title']).' role', 'created_by' => $owner->id],
        ));

        $calendar = HolidayCalendar::query()->firstOrCreate(
            ['year' => (int) now()->format('Y'), 'name' => 'Nigeria Public Holidays'],
            ['created_by' => $owner->id],
        );

        foreach ([
            ['date' => now()->startOfYear()->format('Y-m-d'), 'name' => 'New Year Day', 'is_recurring' => true],
            ['date' => now()->setMonth(5)->setDay(1)->format('Y-m-d'), 'name' => 'Workers Day', 'is_recurring' => true],
        ] as $holiday) {
            $calendar->dates()->firstOrCreate(
                ['date' => $holiday['date'], 'name' => $holiday['name']],
                [...$holiday, 'created_by' => $owner->id],
            );
        }
    }
}
