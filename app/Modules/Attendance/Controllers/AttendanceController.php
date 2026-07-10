<?php

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Imports\AttendanceLogsImport;
use App\Modules\Attendance\Models\AttendanceLog;
use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Employee\Models\Employee;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $service)
    {
    }

    /**
     * Monthly attendance grid: one row per employee with per-day statuses
     * and the running summary. ?period=YYYY-MM (defaults to current month).
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_VIEW), 403);

        $period = $this->validPeriod($request->query('period'));

        $employees = Employee::query()
            ->where('employment_status', '!=', Employee::STATUS_EXITED)
            ->when($request->filled('department_id'), function ($query) use ($request) {
                $query->whereHas('currentAssignment', fn ($q) => $q->where('department_id', $request->integer('department_id')));
            })
            ->with('currentAssignment.department')
            ->orderBy('first_name')
            ->get();

        $summaries = AttendanceSummary::query()
            ->where('period', $period)
            ->get()
            ->keyBy('employee_id');

        $rows = $employees->map(function (Employee $employee) use ($period, $summaries) {
            $days = collect($this->service->daysFor($employee, $period))
                ->map(fn ($day) => [
                    'status' => $day->status,
                    'late_minutes' => $day->lateMinutes,
                    'overtime_minutes' => $day->overtimeMinutes,
                ]);

            $summary = $summaries->get($employee->id);

            return [
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->full_name,
                    'employee_number' => $employee->employee_number,
                    'department' => $employee->currentAssignment?->department?->name,
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
            ];
        });

        return response()->json([
            'data' => [
                'period' => $period,
                'is_finalized' => $this->service->isFinalized($period),
                'rows' => $rows,
            ],
        ]);
    }

    public function storeLog(\App\Modules\Attendance\Requests\StoreAttendanceLogRequest $request)
    {
        $data = $request->validated();

        $this->guardOpenPeriod(substr($data['date'], 0, 7));

        $log = AttendanceLog::query()->updateOrCreate(
            ['employee_id' => $data['employee_id'], 'date' => $data['date']],
            [
                'clock_in' => $data['clock_in'] ?? null,
                'clock_out' => $data['clock_out'] ?? null,
                'note' => $data['note'] ?? null,
                'source' => AttendanceLog::SOURCE_MANUAL,
                'created_by' => $request->user()->id,
            ],
        );

        return response()->json([
            'data' => [
                'id' => $log->id,
                'employee_id' => $log->employee_id,
                'date' => $log->date->toDateString(),
                'clock_in' => $log->clock_in ? substr((string) $log->clock_in, 0, 5) : null,
                'clock_out' => $log->clock_out ? substr((string) $log->clock_out, 0, 5) : null,
            ],
        ], 201);
    }

    public function import(Request $request)
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_MANAGE), 403);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $import = new AttendanceLogsImport($request->user()->id);
        Excel::import($import, $request->file('file'));

        return response()->json([
            'data' => [
                'imported' => $import->imported,
                'skipped' => $import->skipped,
                'errors' => $import->errors,
            ],
        ]);
    }

    public function finalize(Request $request, string $period)
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_APPROVE), 403);

        $period = $this->validPeriod($period);
        $count = $this->service->finalize($period, $request->user());

        return response()->json([
            'data' => ['period' => $period, 'finalized' => $count],
            'message' => "Attendance finalized for {$count} employees.",
        ]);
    }

    private function guardOpenPeriod(string $period): void
    {
        abort_if(
            $this->service->isFinalized($period),
            409,
            'Attendance for this period is finalized and can no longer be edited.',
        );
    }

    private function validPeriod(?string $period): string
    {
        if (! $period || ! preg_match('/^\d{4}-\d{2}$/', $period)) {
            return Carbon::now()->format('Y-m');
        }

        return $period;
    }
}
