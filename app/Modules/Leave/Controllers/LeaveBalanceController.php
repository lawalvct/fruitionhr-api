<?php

namespace App\Modules\Leave\Controllers;

use App\Modules\Leave\Models\LeaveBalance;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

class LeaveBalanceController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can(Permissions::LEAVE_VIEW), 403);

        $year = $request->integer('year') ?: (int) Carbon::now()->year;

        $balances = LeaveBalance::query()
            ->with(['employee', 'leaveType'])
            ->where('year', $year)
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->get()
            ->map(fn (LeaveBalance $balance) => [
                'id' => $balance->id,
                'employee' => ['id' => $balance->employee->id, 'name' => $balance->employee->full_name],
                'leave_type' => ['id' => $balance->leaveType->id, 'name' => $balance->leaveType->name],
                'year' => $balance->year,
                'allocated' => $balance->allocated,
                'carried_forward' => $balance->carried_forward,
                'taken' => $balance->taken,
                'remaining' => $balance->remaining,
            ]);

        return response()->json(['data' => $balances]);
    }
}
