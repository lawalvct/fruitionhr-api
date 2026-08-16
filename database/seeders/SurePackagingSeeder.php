<?php

namespace Database\Seeders;

use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Attendance\Models\AttendanceLog;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Employee\Models\Employee;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveService;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Performance\Models\AppraisalAssignment;
use App\Modules\Performance\Models\AppraisalCycle;
use App\Modules\Performance\Models\AppraisalResult;
use App\Modules\Performance\Models\AppraisalTemplate;
use App\Modules\Performance\Services\PerformanceDefaultsProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A presentation-ready tenant for dashboard and end-to-end demonstrations.
 *
 * Run:
 *   php artisan db:seed --class=SurePackagingSeeder
 *
 * Login:
 *   owner@surepackaging.test / Password123!
 */
class SurePackagingSeeder extends DemoSeeder
{
    private const TENANT_NAME = 'Sure Packaging Limited';

    private const TENANT_SLUG = 'sure-packaging-limited';

    private const EMAIL_SLUG = 'surepackaging';

    private const PASSWORD = 'Password123!';

    public function run(): void
    {
        config(['queue.default' => 'sync']);

        $this->call(PermissionSeeder::class);

        $tenant = Tenant::query()
            ->where('slug', self::TENANT_SLUG)
            ->orWhere('name', self::TENANT_NAME)
            ->first();

        if ($tenant === null) {
            $this->seedCompany(
                name: self::TENANT_NAME,
                email: self::EMAIL_SLUG,
                grades: [
                    ['Packaging Assistant', 18_000_000],
                    ['Technical Officer', 32_000_000],
                    ['Supervisor', 48_000_000],
                    ['Manager', 72_000_000],
                ],
                departmentNames: [
                    'Production',
                    'Quality Assurance',
                    'Warehouse & Logistics',
                    'Finance',
                    'Human Resources',
                    'Sales',
                ],
                employeeCount: 14,
            );

            $tenant = Tenant::query()->where('slug', self::TENANT_SLUG)->firstOrFail();
        } else {
            $this->command?->warn(self::TENANT_NAME.' already exists; refreshing its dashboard demo records.');
        }

        app(CurrentTenant::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        try {
            $owner = User::query()->where('email', 'owner@'.self::EMAIL_SLUG.'.test')->firstOrFail();

            $tenant->update([
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'onboarding_step' => 5,
                'onboarding_completed_at' => now(),
            ]);

            $this->nameDemoUsers($owner);

            $employees = Employee::query()->orderBy('id')->get();
            $shift = Shift::query()->firstOrFail();
            $shift->update([
                'name' => 'Packaging Operations Shift',
                // A packaging plant can operate every day. This also keeps the
                // "today" dashboard meaningful whenever the seeder is run.
                'working_days' => [1, 2, 3, 4, 5, 6, 7],
            ]);

            $this->seedCurrentLeave($owner, $employees);
            $this->seedMonthToDateAttendance($owner, $employees);

            $previousPeriod = now()->subMonthNoOverflow()->format('Y-m');
            if (! PayrollRun::query()->where('period', $previousPeriod)->exists()) {
                $this->seedAttendancePeriod($previousPeriod, $owner, $employees);
                $this->seedLockedPayroll($previousPeriod, $owner);
            }

            $this->seedPerformanceResults($owner, $employees);

            $this->report($tenant, $employees);
        } finally {
            app(CurrentTenant::class)->forget();
        }
    }

    private function nameDemoUsers(User $owner): void
    {
        $owner->update(['name' => 'Amina Yusuf']);

        User::query()
            ->where('email', 'hr@'.self::EMAIL_SLUG.'.test')
            ->update(['name' => 'Bimpe Adewale']);

        User::query()
            ->where('email', 'manager@'.self::EMAIL_SLUG.'.test')
            ->update(['name' => 'Chinedu Okoro']);
    }

    /**
     * Create one employee on approved leave today and one genuine workflow
     * request waiting in the owner's approval inbox.
     */
    private function seedCurrentLeave(User $owner, $employees): void
    {
        /** @var LeaveType $annual */
        $annual = LeaveType::query()->where('code', 'ANN')->firstOrFail();
        $today = today()->toDateString();
        $outEmployee = $employees->get(1);

        $leave = LeaveRequest::query()->firstOrCreate(
            [
                'employee_id' => $outEmployee->id,
                'leave_type_id' => $annual->id,
                'start_date' => $today,
                'end_date' => $today,
            ],
            [
                'days' => 1,
                'reason' => 'Personal day',
                'status' => LeaveRequest::STATUS_APPROVED,
                'requested_by' => $owner->id,
            ],
        );

        if ($leave->wasRecentlyCreated) {
            LeaveBalance::query()
                ->where('employee_id', $outEmployee->id)
                ->where('leave_type_id', $annual->id)
                ->where('year', (int) today()->year)
                ->increment('taken', 1);
        }

        $pendingEmployee = $employees->get(2);
        $pendingReason = 'School appointment — demo approval';

        if (! LeaveRequest::query()->where('reason', $pendingReason)->exists()) {
            $start = today()->addDay();
            while ($start->isWeekend()) {
                $start->addDay();
            }

            app(LeaveService::class)->apply(
                employee: $pendingEmployee,
                type: $annual,
                start: $start->toDateString(),
                end: $start->toDateString(),
                reason: $pendingReason,
                requestedBy: $owner,
            );
        }
    }

    /**
     * Populate every elapsed day in the current month. Today resolves to
     * 11 present, 1 on leave and 2 absent with the default 14 employees.
     */
    private function seedMonthToDateAttendance(User $owner, $employees): void
    {
        $today = today();
        $lastTwo = $employees->take(-2)->pluck('id')->all();
        $leaveEmployeeId = $employees->get(1)->id;

        foreach (CarbonPeriod::create($today->copy()->startOfMonth(), $today) as $day) {
            foreach ($employees as $index => $employee) {
                $isToday = $day->isSameDay($today);

                if (($isToday && in_array($employee->id, $lastTwo, true))
                    || ($isToday && $employee->id === $leaveEmployeeId)
                    || (! $isToday && ($index + $day->day) % 17 === 0)) {
                    continue;
                }

                $late = ($index + $day->day) % 11 === 0;

                AttendanceLog::query()->updateOrCreate(
                    ['employee_id' => $employee->id, 'date' => $day->toDateString()],
                    [
                        'clock_in' => $late ? '08:32:00' : '07:58:00',
                        'clock_out' => $index % 5 === 0 ? '17:25:00' : '17:05:00',
                        'source' => AttendanceLog::SOURCE_IMPORT,
                        'note' => 'Sure Packaging dashboard demo',
                        'created_by' => $owner->id,
                    ],
                );
            }
        }
    }

    private function seedAttendancePeriod(string $period, User $owner, $employees): void
    {
        $attendance = app(AttendanceService::class);

        if ($attendance->isFinalized($period)) {
            return;
        }

        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        foreach (CarbonPeriod::create($start, $end) as $day) {
            foreach ($employees as $index => $employee) {
                if (($index + $day->day) % 19 === 0) {
                    continue;
                }

                $late = ($index + $day->day) % 9 === 0;

                AttendanceLog::query()->updateOrCreate(
                    ['employee_id' => $employee->id, 'date' => $day->toDateString()],
                    [
                        'clock_in' => $late ? '08:35:00' : '07:55:00',
                        'clock_out' => $index % 4 === 0 ? '17:30:00' : '17:00:00',
                        'source' => AttendanceLog::SOURCE_IMPORT,
                        'note' => 'Historical payroll attendance',
                        'created_by' => $owner->id,
                    ],
                );
            }
        }

        $attendance->finalize($period, $owner);
    }

    private function seedLockedPayroll(string $period, User $owner): void
    {
        $runService = app(PayrollRunService::class);
        $run = $runService->createRun($period, $owner);
        $runService->submit($run->refresh(), $owner);

        $request = WorkflowRequest::query()
            ->where('module', 'payroll')
            ->where('record_id', $run->id)
            ->firstOrFail();

        $workflow = app(WorkflowService::class);
        $workflow->act($request, $owner, 'approve', 'Demo HR approval');
        $workflow->act($request->refresh(), $owner, 'approve', 'Demo owner approval');

        $runService->lock($run->refresh());
    }

    private function seedPerformanceResults(User $owner, $employees): void
    {
        app(PerformanceDefaultsProvisioner::class)->provision($owner);

        $template = AppraisalTemplate::query()
            ->where('name', 'General Staff Appraisal')
            ->firstOrFail();

        $cycleEnd = today()->subMonthNoOverflow()->endOfMonth();
        $cycleStart = $cycleEnd->copy()->subMonths(5)->startOfMonth();
        $cycleName = 'Packaging Performance Review '.$cycleEnd->year;

        $cycle = AppraisalCycle::query()->firstOrCreate(
            ['name' => $cycleName],
            [
                'appraisal_type' => 'mid_year',
                'starts_at' => $cycleStart->toDateString(),
                'ends_at' => $cycleEnd->toDateString(),
                'review_starts_at' => $cycleEnd->copy()->subDays(14)->toDateString(),
                'review_ends_at' => $cycleEnd->toDateString(),
                'status' => 'closed',
                'self_review_enabled' => true,
                'calibration_enabled' => false,
                'appeal_window_days' => 7,
                'created_by' => $owner->id,
            ],
        );

        $scores = [8600, 8200, 7900, 9100, 7400, 7700, 8400, 8800, 7200, 8100];

        foreach ($employees->take(count($scores))->values() as $index => $employee) {
            $assignment = AppraisalAssignment::query()->firstOrCreate(
                [
                    'appraisal_cycle_id' => $cycle->id,
                    'employee_id' => $employee->id,
                ],
                [
                    'appraisal_template_id' => $template->id,
                    'status' => 'completed',
                    'due_date' => $cycleEnd->toDateString(),
                    'assigned_by' => $owner->id,
                ],
            );

            $score = $scores[$index];

            AppraisalResult::query()->updateOrCreate(
                ['appraisal_assignment_id' => $assignment->id],
                [
                    'final_score_basis_points' => $score,
                    'raw_score_basis_points' => $score,
                    'grade' => match (true) {
                        $score >= 9000 => 'Outstanding',
                        $score >= 7500 => 'Exceeds Expectations',
                        $score >= 6000 => 'Meets Expectations',
                        default => 'Needs Improvement',
                    },
                    'status' => AppraisalResult::STATUS_APPROVED,
                    'approved_by' => $owner->id,
                    'approved_at' => $cycleEnd,
                    'finalised_at' => $cycleEnd,
                ],
            );
        }
    }

    private function report(Tenant $tenant, $employees): void
    {
        $period = now()->format('Y-m');

        $this->command?->newLine();
        $this->command?->info(self::TENANT_NAME.' demo data is ready.');
        $this->command?->table(
            ['Record', 'Count'],
            [
                ['Employees', $employees->count()],
                ['Current-month attendance logs', AttendanceLog::query()->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->toDateString()])->count()],
                ['Locked payroll runs', PayrollRun::query()->whereIn('status', [PayrollRun::STATUS_LOCKED, PayrollRun::STATUS_PAID])->count()],
                ['Completed appraisal results', AppraisalResult::query()->count()],
                ['Pending approvals', WorkflowRequest::query()->where('status', WorkflowRequest::STATUS_PENDING)->count()],
            ],
        );
        $this->command?->info('Dashboard period: '.$period);
        $this->command?->info('Owner login: owner@'.self::EMAIL_SLUG.'.test');
        $this->command?->info('HR login:    hr@'.self::EMAIL_SLUG.'.test');
        $this->command?->info('Password:    '.self::PASSWORD);
        $this->command?->info('Tenant slug: '.$tenant->slug);
    }
}
