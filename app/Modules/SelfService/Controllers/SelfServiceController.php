<?php

namespace App\Modules\SelfService\Controllers;

use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Resources\EmployeeResource;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Resources\LeaveRequestResource;
use App\Modules\Leave\Services\LeaveService;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\SelfService\Requests\StoreProfileUpdateRequest;
use App\Modules\SelfService\Requests\StoreSelfLeaveRequest;
use App\Modules\SelfService\Resources\PayslipResource;
use App\Modules\SelfService\Resources\ProfileUpdateRequestResource;
use App\Modules\SelfService\Models\ProfileUpdateRequest;
use App\Modules\SelfService\Services\ProfileUpdateService;
use App\Support\Authorization\Permissions;
use App\Support\Money\Naira;
use App\Support\Tenancy\CurrentTenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

class SelfServiceController extends Controller
{
    private const PAYSLIP_STATUSES = [
        PayrollRun::STATUS_APPROVED,
        PayrollRun::STATUS_LOCKED,
        PayrollRun::STATUS_PAID,
    ];

    public function profile(Request $request): EmployeeResource
    {
        abort_unless($request->user()->can(Permissions::ESS_PROFILE_VIEW), 403);

        return new EmployeeResource($this->employeeFor($request));
    }

    public function profileUpdateRequests(Request $request)
    {
        abort_unless($request->user()->can(Permissions::ESS_PROFILE_UPDATE), 403);

        $employee = $this->employeeFor($request, withProfile: false);

        $requests = ProfileUpdateRequest::query()
            ->where('employee_id', $employee->id)
            ->latest('submitted_at')
            ->get();

        return ProfileUpdateRequestResource::collection($requests);
    }

    public function storeProfileUpdateRequest(StoreProfileUpdateRequest $request, ProfileUpdateService $service)
    {
        $employee = $this->employeeFor($request, withProfile: false);

        $updateRequest = $service->submit($employee, $request->user(), $request->validated());

        return (new ProfileUpdateRequestResource($updateRequest))
            ->response()
            ->setStatusCode(201);
    }

    public function leaveRequests(Request $request)
    {
        abort_unless($request->user()->can(Permissions::ESS_LEAVE_VIEW), 403);

        $employee = $this->employeeFor($request, withProfile: false);

        $requests = LeaveRequest::query()
            ->with(['employee', 'leaveType'])
            ->where('employee_id', $employee->id)
            ->latest('start_date')
            ->get();

        return LeaveRequestResource::collection($requests);
    }

    public function leaveTypes(Request $request)
    {
        abort_unless($request->user()->can(Permissions::ESS_LEAVE_VIEW), 403);

        $types = LeaveType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'code' => $type->code,
                'is_paid' => $type->is_paid,
                'requires_document' => $type->requires_document,
                'is_active' => $type->is_active,
            ]);

        return response()->json(['data' => $types]);
    }

    public function storeLeaveRequest(StoreSelfLeaveRequest $request, LeaveService $leave)
    {
        $employee = $this->employeeFor($request, withProfile: false);
        $data = $request->validated();
        $type = LeaveType::query()->findOrFail($data['leave_type_id']);

        $leaveRequest = $leave->apply(
            $employee,
            $type,
            $data['start_date'],
            $data['end_date'],
            $data['reason'] ?? null,
            $request->user(),
        );

        return (new LeaveRequestResource($leaveRequest->load(['employee', 'leaveType'])))
            ->response()
            ->setStatusCode(201);
    }

    public function leaveBalances(Request $request)
    {
        abort_unless($request->user()->can(Permissions::ESS_LEAVE_VIEW), 403);

        $employee = $this->employeeFor($request, withProfile: false);
        $year = $request->integer('year') ?: (int) Carbon::now()->year;

        $balances = LeaveBalance::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->get()
            ->map(fn (LeaveBalance $balance) => [
                'id' => $balance->id,
                'leave_type' => ['id' => $balance->leaveType->id, 'name' => $balance->leaveType->name],
                'year' => $balance->year,
                'allocated' => $balance->allocated,
                'carried_forward' => $balance->carried_forward,
                'taken' => $balance->taken,
                'remaining' => $balance->remaining,
            ]);

        return response()->json(['data' => $balances]);
    }

    public function attendance(Request $request, AttendanceService $attendance)
    {
        abort_unless($request->user()->can(Permissions::ESS_ATTENDANCE_VIEW), 403);

        $employee = $this->employeeFor($request, withProfile: false);
        $period = $this->validPeriod($request->query('period'));
        $summary = AttendanceSummary::query()
            ->where('employee_id', $employee->id)
            ->where('period', $period)
            ->first();

        $days = collect($attendance->daysFor($employee, $period))
            ->map(fn ($day) => [
                'status' => $day->status,
                'late_minutes' => $day->lateMinutes,
                'overtime_minutes' => $day->overtimeMinutes,
            ]);

        return response()->json([
            'data' => [
                'period' => $period,
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->full_name,
                    'employee_number' => $employee->employee_number,
                ],
                'days' => $days,
                'summary' => $summary ? [
                    'days_present' => $summary->days_present,
                    'days_late' => $summary->days_late,
                    'days_absent' => $summary->days_absent,
                    'days_on_leave' => $summary->days_on_leave,
                    'late_minutes' => $summary->late_minutes,
                    'overtime_minutes' => $summary->overtime_minutes,
                    'status' => $summary->status,
                ] : null,
            ],
        ]);
    }

    public function payslips(Request $request)
    {
        abort_unless($request->user()->can(Permissions::ESS_PAYSLIPS_VIEW), 403);

        $employee = $this->employeeFor($request, withProfile: false);

        $payslips = PayrollRunEmployee::query()
            ->with('run')
            ->where('employee_id', $employee->id)
            ->whereHas('run', fn ($query) => $query->whereIn('status', self::PAYSLIP_STATUSES))
            ->latest('id')
            ->get();

        return PayslipResource::collection($payslips);
    }

    public function downloadPayslip(Request $request, PayrollRunEmployee $payslip)
    {
        abort_unless($request->user()->can(Permissions::ESS_PAYSLIPS_VIEW), 403);

        $employee = $this->employeeFor($request, withProfile: false);

        abort_unless($payslip->employee_id === $employee->id, 404);

        $payslip->load(['run', 'items']);
        abort_unless(in_array($payslip->run->status, self::PAYSLIP_STATUSES, true), 409, 'Payslip is not available yet.');

        $items = $payslip->items;
        $earnings = $items->where('category', PayrollItem::CATEGORY_EARNING);
        $deductions = $items->whereIn('category', [PayrollItem::CATEGORY_STATUTORY, PayrollItem::CATEGORY_DEDUCTION]);

        $pdf = Pdf::loadView('payroll.payslip', [
            'company' => app(CurrentTenant::class)->get()?->name ?? 'Company',
            'periodLabel' => Carbon::createFromFormat('Y-m', $payslip->run->period)->format('F Y'),
            'employee' => $payslip->snapshot['employee'] ?? ['name' => $employee->full_name, 'employee_number' => $employee->employee_number],
            'structure' => $payslip->snapshot['structure'] ?? null,
            'earnings' => $earnings->map($this->payslipLine(...))->values(),
            'deductions' => $deductions->map($this->payslipLine(...))->values(),
            'grossFormatted' => Naira::format($payslip->gross),
            'deductionsFormatted' => Naira::format($payslip->total_deductions),
            'netFormatted' => Naira::format($payslip->net),
        ]);

        $name = str($employee->employee_number)->slug();

        return $pdf->download("payslip-{$name}-{$payslip->run->period}.pdf");
    }

    private function employeeFor(Request $request, bool $withProfile = true): Employee
    {
        $query = $request->user()->employee();

        if ($withProfile) {
            $query->with([
                'currentAssignment.branch',
                'currentAssignment.department',
                'currentAssignment.position',
                'currentAssignment.jobGrade',
                'currentAssignment.employmentType',
                'currentAssignment.supervisor',
                'employmentRecords.branch',
                'employmentRecords.department',
                'employmentRecords.position',
                'employmentRecords.jobGrade',
                'employmentRecords.employmentType',
                'employmentRecords.supervisor',
                'contacts',
                'bankAccounts',
                'statutoryDetails',
            ]);
        }

        $employee = $query->first();

        abort_if($employee === null, 404, 'No employee profile is linked to this user.');

        return $employee;
    }

    private function validPeriod(?string $period): string
    {
        if (! $period || ! preg_match('/^\d{4}-\d{2}$/', $period)) {
            return Carbon::now()->format('Y-m');
        }

        return $period;
    }

    private function payslipLine(PayrollItem $item): array
    {
        return ['name' => $item->name, 'formatted' => Naira::format($item->amount)];
    }
}
