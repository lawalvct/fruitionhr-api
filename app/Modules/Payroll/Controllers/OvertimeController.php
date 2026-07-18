<?php

namespace App\Modules\Payroll\Controllers;

use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Payroll\Models\OvertimePayment;
use App\Modules\Payroll\Requests\StoreOvertimePaymentRequest;
use App\Modules\Payroll\Resources\OvertimePaymentResource;
use App\Modules\Payroll\Services\OvertimeService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OvertimeController extends Controller
{
    public function __construct(private readonly OvertimeService $service)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can(Permissions::OVERTIME_VIEW), 403);

        $overtime = OvertimePayment::query()
            ->with('employee')
            ->when($request->query('period'), fn ($q, $p) => $q->where('period', $p))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
            ->when($request->query('disbursement_mode'), fn ($q, $m) => $q->where('disbursement_mode', $m))
            ->latest('id')
            ->paginate(50);

        return OvertimePaymentResource::collection($overtime);
    }

    public function store(StoreOvertimePaymentRequest $request)
    {
        $overtime = $this->service->createManual($request->validated(), $request->user());

        return OvertimePaymentResource::make($overtime->load('employee'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, OvertimePayment $overtime)
    {
        abort_unless($request->user()->can(Permissions::OVERTIME_VIEW), 403);

        return OvertimePaymentResource::make($overtime->load('employee'));
    }

    public function update(StoreOvertimePaymentRequest $request, OvertimePayment $overtime)
    {
        $updated = $this->service->update($overtime, $request->validated());

        return OvertimePaymentResource::make($updated->load('employee'));
    }

    public function destroy(Request $request, OvertimePayment $overtime)
    {
        abort_unless($request->user()->can(Permissions::OVERTIME_MANAGE), 403);

        if (! $overtime->isEditable()) {
            throw new ConflictHttpException('Only draft or rejected overtime can be deleted.');
        }

        $overtime->delete();

        return response()->noContent();
    }

    public function submit(Request $request, OvertimePayment $overtime)
    {
        abort_unless($request->user()->can(Permissions::OVERTIME_MANAGE), 403);

        $this->service->submit($overtime, $request->user());

        return OvertimePaymentResource::make($overtime->refresh()->load('employee'));
    }

    /** Pay an approved off-cycle overtime record — gross, no tax. */
    public function pay(Request $request, OvertimePayment $overtime)
    {
        abort_unless($request->user()->can(Permissions::OVERTIME_MANAGE), 403);

        $this->service->payOffCycle($overtime);

        return OvertimePaymentResource::make($overtime->refresh()->load('employee'));
    }

    /** Convert an attendance summary's clocked overtime into a priced record. */
    public function fromAttendance(Request $request)
    {
        abort_unless($request->user()->can(Permissions::OVERTIME_MANAGE), 403);

        $multipliers = array_map(fn ($m) => (float) $m, config('payroll.overtime.multipliers', [1, 1.5, 2]));

        $data = $request->validate([
            'attendance_summary_id' => ['required', 'integer', Rule::exists('attendance_summaries', 'id')],
            'multiplier' => ['required', 'numeric', Rule::in($multipliers)],
            'disbursement_mode' => ['required', Rule::in([OvertimePayment::MODE_IN_PAYROLL, OvertimePayment::MODE_OFF_CYCLE])],
        ]);

        $summary = AttendanceSummary::query()->findOrFail($data['attendance_summary_id']);

        if ($summary->overtime_minutes <= 0) {
            throw new ConflictHttpException('This attendance record has no overtime to pay.');
        }

        $overtime = $this->service->createFromAttendance(
            $summary,
            (float) $data['multiplier'],
            $data['disbursement_mode'],
            $request->user(),
        );

        return OvertimePaymentResource::make($overtime->load('employee'))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Attendance summaries with clocked overtime in a period, flagged with
     * whether they've already been turned into an overtime payment — the
     * "accept or overlook" worklist.
     */
    public function attendanceCandidates(Request $request)
    {
        abort_unless($request->user()->can(Permissions::OVERTIME_VIEW), 403);

        $period = $request->query('period', now()->format('Y-m'));

        $converted = OvertimePayment::query()
            ->whereNotNull('attendance_summary_id')
            ->where('period', $period)
            ->pluck('attendance_summary_id')
            ->all();

        $candidates = AttendanceSummary::query()
            ->with('employee')
            ->where('period', $period)
            ->where('status', AttendanceSummary::STATUS_FINALIZED)
            ->where('overtime_minutes', '>', 0)
            ->get()
            ->map(fn (AttendanceSummary $s) => [
                'attendance_summary_id' => $s->id,
                'employee' => ['id' => $s->employee->id, 'name' => $s->employee->full_name, 'number' => $s->employee->employee_number],
                'period' => $s->period,
                'overtime_minutes' => $s->overtime_minutes,
                'overtime_hours' => round($s->overtime_minutes / 60, 2),
                'already_recorded' => in_array($s->id, $converted, true),
            ]);

        return response()->json(['data' => $candidates]);
    }
}
