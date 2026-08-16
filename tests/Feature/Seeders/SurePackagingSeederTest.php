<?php

use App\Core\Workflow\Models\WorkflowRequest;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Performance\Models\AppraisalResult;
use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\SurePackagingSeeder;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('sure packaging seed is repeatable and fills every dashboard area', function () {
    Carbon::setTestNow('2026-08-16 09:00:00');

    $this->seed(SurePackagingSeeder::class);
    $this->seed(SurePackagingSeeder::class);

    $tenant = Tenant::query()->where('slug', 'sure-packaging-limited')->firstOrFail();
    app(CurrentTenant::class)->set($tenant);
    setPermissionsTeamId($tenant->id);

    $employees = Employee::query()->get();
    $statuses = $employees
        ->map(fn (Employee $employee) => app(AttendanceService::class)
            ->daysFor($employee, '2026-08')['2026-08-16']->status)
        ->countBy();

    $present = ($statuses->get('present') ?? 0)
        + ($statuses->get('late') ?? 0)
        + ($statuses->get('early_exit') ?? 0);

    expect($tenant->name)->toBe('Sure Packaging Limited')
        ->and($employees)->toHaveCount(14)
        ->and($present)->toBe(11)
        ->and($statuses->get('on_leave'))->toBe(1)
        ->and($statuses->get('absent'))->toBe(2)
        ->and(PayrollRun::query()->where('status', PayrollRun::STATUS_LOCKED)->count())->toBe(2)
        ->and(AppraisalResult::query()->count())->toBe(10)
        ->and((int) AppraisalResult::query()->avg('final_score_basis_points'))->toBe(8140)
        ->and(WorkflowRequest::query()->where('status', WorkflowRequest::STATUS_PENDING)->count())->toBe(1)
        ->and(Vacancy::query()->where('status', 'open')->count())->toBe(1);
});
