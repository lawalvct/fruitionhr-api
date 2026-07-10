<?php

namespace App\Modules\Leave\Services;

use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Company\Models\HolidayDate;
use App\Modules\Employee\Models\Employee;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function __construct(private readonly WorkflowService $workflow)
    {
    }

    /**
     * Working days in a range (Mon–Fri) minus public holidays. Leave
     * entitlement is measured in working days.
     */
    public function workingDays(string $start, string $end): int
    {
        $startDate = Carbon::parse($start);
        $endDate = Carbon::parse($end);

        $holidays = HolidayDate::query()
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        $days = 0;
        foreach (CarbonPeriod::create($startDate, $endDate) as $date) {
            if ($date->isWeekday() && ! $holidays->has($date->toDateString())) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * Find-or-create the balance for the year, seeding allocation from the
     * leave type's policy (if any) the first time it's referenced.
     */
    public function balanceFor(Employee $employee, LeaveType $type, int $year): LeaveBalance
    {
        $balance = LeaveBalance::query()->firstOrNew([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => $year,
        ]);

        if (! $balance->exists) {
            $policy = LeavePolicy::query()->where('leave_type_id', $type->id)->first();
            $balance->allocated = $policy?->days_per_year ?? 0;
            $balance->carried_forward = 0;
            $balance->taken = 0;
            $balance->save();
        }

        return $balance;
    }

    /**
     * Create a leave request and submit it into the leave approval workflow.
     *
     * @throws ValidationException when dates or balance are invalid
     */
    public function apply(
        Employee $employee,
        LeaveType $type,
        string $start,
        string $end,
        ?string $reason,
        User $requestedBy,
    ): LeaveRequest {
        if (Carbon::parse($end)->lt(Carbon::parse($start))) {
            throw ValidationException::withMessages(['end_date' => 'End date must be on or after the start date.']);
        }

        $days = $this->workingDays($start, $end);

        if ($days < 1) {
            throw ValidationException::withMessages(['start_date' => 'The selected range contains no working days.']);
        }

        $year = (int) Carbon::parse($start)->year;
        $balance = $this->balanceFor($employee, $type, $year);

        // Count days already committed to other pending/approved requests
        // this year so overlapping applications can't overdraw the balance.
        $committed = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED])
            ->whereYear('start_date', $year)
            ->sum('days');

        $available = $balance->remaining - (int) $committed;

        if ($days > $available) {
            throw ValidationException::withMessages([
                'start_date' => "Insufficient leave balance: {$days} day(s) requested, {$available} available.",
            ]);
        }

        return DB::transaction(function () use ($employee, $type, $start, $end, $days, $reason, $requestedBy): LeaveRequest {
            $request = LeaveRequest::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $start,
                'end_date' => $end,
                'days' => $days,
                'reason' => $reason,
                'status' => LeaveRequest::STATUS_PENDING,
                'requested_by' => $requestedBy->id,
            ]);

            $this->workflow->submit($request, 'leave', $requestedBy);

            return $request;
        });
    }

    /** Applied when the workflow finally approves a leave request. */
    public function markApproved(LeaveRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            $request->update(['status' => LeaveRequest::STATUS_APPROVED]);

            $year = (int) Carbon::parse($request->start_date)->year;
            $balance = LeaveBalance::query()->firstOrCreate(
                [
                    'employee_id' => $request->employee_id,
                    'leave_type_id' => $request->leave_type_id,
                    'year' => $year,
                ],
                ['allocated' => 0, 'carried_forward' => 0, 'taken' => 0],
            );

            $balance->increment('taken', $request->days);
        });
    }

    public function markRejected(LeaveRequest $request): void
    {
        $request->update(['status' => LeaveRequest::STATUS_REJECTED]);
    }
}
