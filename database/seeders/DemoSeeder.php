<?php

namespace Database\Seeders;

use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Admin\Models\PlatformRole;
use App\Modules\Attendance\Models\AttendanceLog;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Models\ShiftAssignment;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Company\Models\Branch;
use App\Modules\Company\Models\Department;
use App\Modules\Company\Models\EmploymentType;
use App\Modules\Company\Models\HolidayCalendar;
use App\Modules\Company\Models\JobGrade;
use App\Modules\Company\Models\Position;
use App\Modules\Employee\Actions\CreateEmployee;
use App\Modules\Employee\Models\Employee;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\PerformanceCategory;
use App\Modules\Performance\Models\PerformanceKpi;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\ManpowerRequisition;
use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Tenancy\Actions\RegisterTenant;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use LogicException;

/**
 * Seeds fully-configured demo companies for end-to-end testing: company setup,
 * employees with logins, salaries, finalized attendance, leave, a locked
 * payroll run, and light recruitment/performance data.
 *
 * Run on a fresh database:  php artisan migrate:fresh --seed --seeder=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    private const PAYROLL_PERIOD = '2026-06'; // a complete past month

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new LogicException('Demo data cannot be seeded in production.');
        }

        // Payroll dispatches a queued job; run it inline during seeding.
        config(['queue.default' => 'sync']);

        $this->call(PermissionSeeder::class);

        $this->superAdmin();

        $this->seedCompany(
            name: 'Zenith Manufacturing Ltd',
            email: 'zenith',
            grades: [['Junior', 15_000_000], ['Officer', 28_000_000], ['Manager', 50_000_000]],
            departmentNames: ['Operations', 'Finance', 'Human Resources', 'Engineering'],
            employeeCount: 10,
        );

        $this->seedCompany(
            name: 'Bluewave Technologies Ltd',
            email: 'bluewave',
            grades: [['Associate', 22_000_000], ['Senior', 45_000_000], ['Lead', 70_000_000]],
            departmentNames: ['Product', 'Sales', 'Support'],
            employeeCount: 7,
        );

        $this->command?->info('');
        $this->command?->info('Demo data ready. Logins (password: '.self::PASSWORD.'):');
        $this->command?->info('  Super admin:  admin@fruitionhr.test');
        $this->command?->info('  Zenith:       owner@zenith.test | hr@zenith.test | manager@zenith.test | ada@zenith.test (employee)');
        $this->command?->info('  Bluewave:     owner@bluewave.test | hr@bluewave.test | manager@bluewave.test | ada@bluewave.test (employee)');
    }

    private function superAdmin(): void
    {
        $administrator = User::query()->firstOrNew([
            'email' => 'admin@fruitionhr.test',
        ]);
        $administrator->forceFill([
            'tenant_id' => null,
            'name' => 'Platform Admin',
            'password' => Hash::make(self::PASSWORD),
            'is_super_admin' => true,
            // Without a role this account clears EnsureSuperAdmin and then
            // finds every section closed to it — a console with no sidebar.
            'platform_role_id' => PlatformRole::query()
                ->where('slug', PlatformRole::OWNER_SLUG)
                ->value('id'),
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ])->save();

        $this->seedStaffExamples();
    }

    /**
     * Two limited administrators, so the access model is visible in a demo
     * rather than something you have to go and build before you can see it.
     */
    private function seedStaffExamples(): void
    {
        $examples = [
            ['support@fruitionhr.test', 'Support Desk', 'support-agent'],
            ['editor@fruitionhr.test', 'Blog Editor', 'content-editor'],
        ];

        foreach ($examples as [$email, $name, $roleSlug]) {
            $roleId = PlatformRole::query()->where('slug', $roleSlug)->value('id');

            if ($roleId === null) {
                continue;
            }

            User::query()->firstOrNew(['email' => $email])->forceFill([
                'tenant_id' => null,
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'is_super_admin' => true,
                'platform_role_id' => $roleId,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ])->save();
        }

        $this->command?->info('  Platform:     admin@fruitionhr.test (owner) | support@fruitionhr.test | editor@fruitionhr.test');
    }

    /**
     * Build one complete tenant. Protected so focused demo-company seeders can
     * reuse the same production-like setup without duplicating the domain flow.
     */
    protected function seedCompany(string $name, string $email, array $grades, array $departmentNames, int $employeeCount): void
    {
        $owner = app(RegisterTenant::class)->execute([
            'company_name' => $name,
            'name' => 'Owner '.ucfirst($email),
            'email' => "owner@{$email}.test",
            'phone' => '+234800'.random_int(1000000, 9999999),
            'password' => self::PASSWORD,
        ]);

        // Fetch explicitly (strict mode forbids lazy-loading the relation).
        $tenant = Tenant::query()->findOrFail($owner->tenant_id);
        app(CurrentTenant::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $hr = $this->staffUser($tenant, "hr@{$email}.test", 'HR Manager', 'hr_admin');
        $manager = $this->staffUser($tenant, "manager@{$email}.test", 'Line Manager', 'manager');

        [$branches, $departments, $gradeModels, $positions, $types] =
            $this->companySetup($owner->id, $departmentNames, $grades);

        $structure = $this->salarySetup($owner->id);
        $shift = $this->shift($owner->id);
        $leaveTypes = $this->leaveSetup($owner->id, $types);

        $employees = $this->employees(
            $email, $employeeCount, $owner, $manager, $branches, $departments,
            $gradeModels, $positions, $types, $structure, $shift, $grades, $leaveTypes,
        );

        $this->attendance($tenant->id, $owner, $employees, $shift);
        $this->leaveRequests($owner, $employees, $leaveTypes);
        $this->payroll($owner);
        $this->recruitment($owner, $departments, $positions, $types);
        $this->performance($owner, $employees);

        app(CurrentTenant::class)->forget();
    }

    private function staffUser(Tenant $tenant, string $email, string $name, string $role): User
    {
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'status' => User::STATUS_ACTIVE,
        ]);
        setPermissionsTeamId($tenant->id);
        $user->assignRole($role);

        return $user;
    }

    private function companySetup(int $by, array $departmentNames, array $grades): array
    {
        $hq = Branch::create(['name' => 'Head Office', 'code' => 'HQ', 'city' => 'Lagos', 'state' => 'Lagos', 'is_active' => true, 'created_by' => $by]);
        $factory = Branch::create(['name' => 'Ikeja Plant', 'code' => 'IKJ', 'city' => 'Ikeja', 'state' => 'Lagos', 'is_active' => true, 'created_by' => $by]);
        $branches = [$hq, $factory];

        $departments = [];
        foreach ($departmentNames as $i => $dn) {
            $departments[] = Department::create([
                'name' => $dn,
                'code' => strtoupper(substr($dn, 0, 3)),
                'branch_id' => $branches[$i % 2]->id,
                'is_active' => true,
                'created_by' => $by,
            ]);
        }

        $gradeModels = [];
        foreach ($grades as $level => [$gname]) {
            $gradeModels[] = JobGrade::create([
                'name' => $gname, 'code' => 'G'.($level + 1), 'level' => $level + 1,
                'is_active' => true, 'created_by' => $by,
            ]);
        }

        $types = [
            EmploymentType::create(['name' => 'Full-time', 'is_active' => true, 'created_by' => $by]),
            EmploymentType::create(['name' => 'Contract', 'is_active' => true, 'created_by' => $by]),
        ];

        $positions = [];
        foreach ($departments as $i => $dept) {
            $positions[] = Position::create([
                'title' => $dept->name.' Officer',
                'code' => $dept->code.'-OFF',
                'department_id' => $dept->id,
                'job_grade_id' => $gradeModels[$i % count($gradeModels)]->id,
                'is_active' => true,
                'created_by' => $by,
            ]);
        }

        $calendar = HolidayCalendar::create(['year' => 2026, 'name' => '2026 Public Holidays', 'created_by' => $by]);
        foreach ([['2026-06-12', 'Democracy Day'], ['2026-10-01', 'Independence Day']] as [$date, $hname]) {
            $calendar->dates()->create(['date' => $date, 'name' => $hname, 'is_recurring' => false, 'created_by' => $by]);
        }

        return [$branches, $departments, $gradeModels, $positions, $types];
    }

    private function salarySetup(int $by): SalaryStructure
    {
        $housing = SalaryComponent::create(['name' => 'Housing Allowance', 'code' => 'HOU', 'type' => 'earning', 'calc_type' => 'percent_of_basic', 'percent' => 25, 'is_taxable' => true, 'is_pensionable' => true, 'is_active' => true, 'created_by' => $by]);
        $transport = SalaryComponent::create(['name' => 'Transport Allowance', 'code' => 'TRA', 'type' => 'earning', 'calc_type' => 'percent_of_basic', 'percent' => 15, 'is_taxable' => true, 'is_pensionable' => true, 'is_active' => true, 'created_by' => $by]);
        $meal = SalaryComponent::create(['name' => 'Meal Allowance', 'code' => 'MEAL', 'type' => 'earning', 'calc_type' => 'fixed', 'is_taxable' => true, 'is_pensionable' => false, 'is_active' => true, 'created_by' => $by]);

        $structure = SalaryStructure::create(['name' => 'Standard Structure', 'description' => 'Basic + housing + transport + meal', 'is_active' => true, 'created_by' => $by]);
        $structure->components()->createMany([
            ['salary_component_id' => $housing->id],
            ['salary_component_id' => $transport->id],
            ['salary_component_id' => $meal->id, 'amount' => 2_000_000], // ₦20,000
        ]);

        return $structure;
    }

    private function shift(int $by): Shift
    {
        return Shift::create([
            'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_minutes' => 15, 'working_days' => [1, 2, 3, 4, 5], 'is_active' => true, 'created_by' => $by,
        ]);
    }

    /** @return array<int, LeaveType> */
    private function leaveSetup(int $by, array $types): array
    {
        $annual = LeaveType::create(['name' => 'Annual Leave', 'code' => 'ANN', 'is_paid' => true, 'requires_document' => false, 'is_active' => true, 'created_by' => $by]);
        $sick = LeaveType::create(['name' => 'Sick Leave', 'code' => 'SICK', 'is_paid' => true, 'requires_document' => true, 'is_active' => true, 'created_by' => $by]);

        LeavePolicy::create(['leave_type_id' => $annual->id, 'days_per_year' => 20, 'carry_forward_max' => 5, 'created_by' => $by]);
        LeavePolicy::create(['leave_type_id' => $sick->id, 'days_per_year' => 10, 'carry_forward_max' => 0, 'created_by' => $by]);

        return [$annual, $sick];
    }

    /** @return array<int, Employee> */
    private function employees(
        string $slug, int $count, User $owner, User $manager, array $branches, array $departments,
        array $grades, array $positions, array $types, SalaryStructure $structure, Shift $shift,
        array $gradeSalaries, array $leaveTypes,
    ): array {
        $firstNames = ['Ada', 'Ibrahim', 'Chidi', 'Funke', 'Emeka', 'Zainab', 'Tunde', 'Ngozi', 'Bola', 'Yusuf', 'Amara', 'Kunle'];
        $lastNames = ['Okafor', 'Musa', 'Eze', 'Adeyemi', 'Okonkwo', 'Bello', 'Ade', 'Nwosu', 'Balogun', 'Ibrahim'];

        $employees = [];
        for ($i = 0; $i < $count; $i++) {
            $gradeIndex = $i % count($grades);
            $basic = $gradeSalaries[$gradeIndex][1];
            $first = $firstNames[$i % count($firstNames)];
            $last = $lastNames[$i % count($lastNames)];

            // Link the first employee of each company to an ESS login (ada@slug.test).
            $userId = null;
            if ($i === 0) {
                $essUser = User::query()->create([
                    'tenant_id' => $owner->tenant_id,
                    'name' => "{$first} {$last}",
                    'email' => "ada@{$slug}.test",
                    'password' => Hash::make(self::PASSWORD),
                    'status' => User::STATUS_ACTIVE,
                ]);
                setPermissionsTeamId($owner->tenant_id);
                $essUser->assignRole('employee');
                $userId = $essUser->id;
            }

            $employee = app(CreateEmployee::class)->execute(
                employeeData: [
                    'user_id' => $userId,
                    'first_name' => $first,
                    'last_name' => $last,
                    'official_email' => strtolower("{$first}.{$last}.{$i}@{$slug}.test"),
                    'phone' => '+234701'.random_int(1000000, 9999999),
                    'gender' => $i % 2 === 0 ? 'female' : 'male',
                    'date_of_birth' => Carbon::parse('1990-01-01')->addDays($i * 137)->toDateString(),
                    'marital_status' => $i % 3 === 0 ? 'married' : 'single',
                    'city' => 'Lagos',
                    'state' => 'Lagos',
                    'employment_status' => Employee::STATUS_ACTIVE,
                    'hired_at' => Carbon::parse('2024-01-15')->addMonths($i)->toDateString(),
                ],
                assignmentData: [
                    'branch_id' => $branches[$i % 2]->id,
                    'department_id' => $departments[$i % count($departments)]->id,
                    'position_id' => $positions[$i % count($positions)]->id,
                    'job_grade_id' => $grades[$gradeIndex]->id,
                    'employment_type_id' => $types[0]->id,
                    'supervisor_id' => null,
                    'effective_from' => '2024-01-15',
                ],
                createdBy: $owner->id,
            );

            $employee->contacts()->create(['type' => 'next_of_kin', 'name' => "{$last} Relative", 'relationship' => 'Sibling', 'phone' => '+234809'.random_int(1000000, 9999999), 'created_by' => $owner->id]);
            $employee->bankAccounts()->create(['bank_name' => 'Zenith Bank', 'bank_code' => '057', 'account_number' => (string) random_int(1000000000, 9999999999), 'account_name' => "{$first} {$last}", 'is_primary' => true, 'created_by' => $owner->id]);
            $employee->statutoryDetails()->create(['tax_id' => 'TIN'.random_int(10000000, 99999999), 'pension_pin' => 'PEN'.random_int(100000000, 999999999), 'pension_fund_administrator' => 'Stanbic IBTC Pension', 'nhf_number' => 'NHF'.random_int(1000000, 9999999), 'created_by' => $owner->id]);

            EmployeeSalary::create([
                'employee_id' => $employee->id,
                'salary_structure_id' => $structure->id,
                'basic_salary' => $basic,
                'effective_from' => '2024-01-15',
                'is_current' => true,
                'created_by' => $owner->id,
            ]);

            ShiftAssignment::create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'effective_from' => '2024-01-15', 'is_current' => true, 'created_by' => $owner->id]);

            // Leave balances for the year.
            foreach ([[$leaveTypes[0]->id, 20], [$leaveTypes[1]->id, 10]] as [$typeId, $days]) {
                LeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $typeId, 'year' => 2026, 'allocated' => $days, 'carried_forward' => 0, 'taken' => 0]);
            }

            $employees[] = $employee;
        }

        return $employees;
    }

    private function attendance(int $tenantId, User $owner, array $employees, Shift $shift): void
    {
        [$start, $end] = [Carbon::parse(self::PAYROLL_PERIOD.'-01')->startOfMonth(), Carbon::parse(self::PAYROLL_PERIOD.'-01')->endOfMonth()];

        $rows = [];
        foreach ($employees as $index => $employee) {
            foreach (CarbonPeriod::create($start, $end) as $day) {
                if (! $day->isWeekday()) {
                    continue;
                }
                // Every 7th day for the first employee is a late clock-in, for variety.
                $late = $index === 0 && $day->day % 7 === 0;
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'employee_id' => $employee->id,
                    'date' => $day->toDateString(),
                    'clock_in' => $late ? '08:40:00' : '08:00:00',
                    'clock_out' => '17:00:00',
                    'source' => AttendanceLog::SOURCE_IMPORT,
                    'created_by' => $owner->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Bulk insert (tenant_id set explicitly since events don't fire on insert()).
        collect($rows)->chunk(500)->each(fn ($chunk) => AttendanceLog::insert($chunk->all()));

        app(AttendanceService::class)->finalize(self::PAYROLL_PERIOD, $owner);
    }

    private function leaveRequests(User $owner, array $employees, array $leaveTypes): void
    {
        // One approved and one pending request in a month AFTER the payroll period,
        // so the payroll preflight for 2026-06 stays clean.
        $approved = LeaveRequest::create([
            'employee_id' => $employees[1]->id,
            'leave_type_id' => $leaveTypes[0]->id,
            'start_date' => '2026-08-10', 'end_date' => '2026-08-14', 'days' => 5,
            'reason' => 'Family holiday', 'status' => LeaveRequest::STATUS_APPROVED, 'requested_by' => $owner->id,
        ]);
        LeaveBalance::query()
            ->where('employee_id', $employees[1]->id)
            ->where('leave_type_id', $leaveTypes[0]->id)
            ->where('year', 2026)
            ->increment('taken', $approved->days);

        LeaveRequest::create([
            'employee_id' => $employees[2]->id,
            'leave_type_id' => $leaveTypes[1]->id,
            'start_date' => '2026-08-03', 'end_date' => '2026-08-04', 'days' => 2,
            'reason' => 'Medical appointment', 'status' => LeaveRequest::STATUS_PENDING, 'requested_by' => $owner->id,
        ]);
    }

    private function payroll(User $owner): void
    {
        $runService = app(PayrollRunService::class);
        $run = $runService->createRun(self::PAYROLL_PERIOD, $owner); // sync job → status review

        $runService->submit($run->refresh(), $owner);

        $wf = WorkflowRequest::query()->where('module', 'payroll')->where('record_id', $run->id)->firstOrFail();
        $workflow = app(WorkflowService::class);
        $workflow->act($wf, $owner, 'approve');            // HR step (owner may act on any)
        $workflow->act($wf->refresh(), $owner, 'approve'); // Owner step

        $runService->lock($run->refresh());
    }

    private function recruitment(User $owner, array $departments, array $positions, array $types): void
    {
        $requisition = ManpowerRequisition::create([
            'department_id' => $departments[0]->id,
            'position_id' => $positions[0]->id,
            'employment_type_id' => $types[0]->id,
            'requested_by' => $owner->id,
            'title' => 'Production Supervisor',
            'headcount' => 2,
            'target_start_date' => '2026-09-01',
            'reason' => 'Expansion of the production line',
            'status' => 'approved',
            'created_by' => $owner->id,
        ]);

        $vacancy = Vacancy::create([
            'manpower_requisition_id' => $requisition->id,
            'employment_type_id' => $types[0]->id,
            'title' => 'Production Supervisor',
            'code' => 'VAC-001',
            'description' => 'Oversee daily production operations.',
            'requirements' => '5+ years manufacturing experience.',
            'location' => 'Ikeja, Lagos',
            'positions_available' => 2,
            'opens_at' => '2026-07-01',
            'status' => 'open',
            'created_by' => $owner->id,
        ]);

        foreach ([['Grace', 'Umeh', 'applied'], ['Sola', 'Ojo', 'interview']] as [$first, $last, $stage]) {
            $applicant = Applicant::create([
                'first_name' => $first, 'last_name' => $last,
                'email' => strtolower("{$first}.{$last}@example.com"),
                'phone' => '+234803'.random_int(1000000, 9999999),
                'city' => 'Lagos', 'state' => 'Lagos', 'created_by' => $owner->id,
            ]);
            Application::create([
                'vacancy_id' => $vacancy->id,
                'applicant_id' => $applicant->id,
                'stage' => $stage,
                'source' => 'website',
                'applied_at' => now(),
                'created_by' => $owner->id,
            ]);
        }
    }

    private function performance(User $owner, array $employees): void
    {
        $category = PerformanceCategory::create(['name' => 'Core Competencies', 'description' => 'Company-wide behavioural competencies', 'is_active' => true, 'created_by' => $owner->id]);
        foreach (['Quality of Work', 'Teamwork', 'Initiative'] as $kpi) {
            PerformanceKpi::create(['performance_category_id' => $category->id, 'name' => $kpi, 'measurement_unit' => 'rating', 'is_active' => true, 'created_by' => $owner->id]);
        }

        foreach (array_slice($employees, 0, 3) as $employee) {
            Goal::create([
                'level' => 'individual',
                'title' => 'Reduce process errors by 10%',
                'description' => 'Improve first-pass yield across the quarter.',
                'employee_id' => $employee->id,
                'owner_user_id' => $employee->user_id,
                'weight' => 100,
                'progress' => random_int(10, 80),
                'status' => 'in_progress',
                'starts_at' => '2026-07-01',
                'due_at' => '2026-09-30',
                'created_by' => $owner->id,
            ]);
        }
    }
}
