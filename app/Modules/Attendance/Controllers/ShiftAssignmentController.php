<?php

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Models\ShiftAssignment;
use App\Modules\Employee\Models\Employee;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShiftAssignmentController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_MANAGE), 403);

        $assignments = ShiftAssignment::query()
            ->with('shift')
            ->where('is_current', true)
            ->get()
            ->keyBy('employee_id');

        $employees = Employee::query()
            ->where('employment_status', '!=', Employee::STATUS_EXITED)
            ->with('currentAssignment.department')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return response()->json([
            'data' => $employees->map(function (Employee $employee) use ($assignments): array {
                /** @var ShiftAssignment|null $assignment */
                $assignment = $assignments->get($employee->id);

                return [
                    'employee' => [
                        'id' => $employee->id,
                        'employee_number' => $employee->employee_number,
                        'name' => $employee->full_name,
                        'department' => $employee->currentAssignment?->department ? [
                            'id' => $employee->currentAssignment->department->id,
                            'name' => $employee->currentAssignment->department->name,
                        ] : null,
                    ],
                    'assignment' => $assignment?->shift ? [
                        'id' => $assignment->id,
                        'effective_from' => $assignment->effective_from->toDateString(),
                        'shift' => [
                            'id' => $assignment->shift->id,
                            'name' => $assignment->shift->name,
                            'start_time' => substr((string) $assignment->shift->start_time, 0, 5),
                            'end_time' => substr((string) $assignment->shift->end_time, 0, 5),
                        ],
                    ] : null,
                ];
            })->values(),
        ]);
    }

    /**
     * Assign an employee to a shift, closing any current assignment. History
     * is preserved (effective dates), mirroring employment records.
     */
    public function store(Request $request)
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_MANAGE), 403);

        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'shift_id' => ['required', 'integer', Rule::exists('shifts', 'id')->where('tenant_id', $tenantId)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        return DB::transaction(function () use ($data, $request) {
            ShiftAssignment::query()
                ->where('employee_id', $data['employee_id'])
                ->where('is_current', true)
                ->update(['is_current' => false, 'effective_to' => $data['effective_from']]);

            $assignment = ShiftAssignment::query()->create([
                'employee_id' => $data['employee_id'],
                'shift_id' => $data['shift_id'],
                'effective_from' => $data['effective_from'],
                'is_current' => true,
                'created_by' => $request->user()->id,
            ]);

            return response()->json([
                'data' => [
                    'id' => $assignment->id,
                    'employee_id' => $assignment->employee_id,
                    'shift_id' => $assignment->shift_id,
                    'effective_from' => $assignment->effective_from->toDateString(),
                ],
                'message' => 'Shift assigned successfully.',
            ], 201);
        });
    }
}
