<?php

namespace App\Modules\Leave\Controllers;

use App\Modules\Employee\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Requests\StoreLeaveRequestRequest;
use App\Modules\Leave\Resources\LeaveRequestResource;
use App\Modules\Leave\Services\LeaveService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class LeaveRequestController extends Controller
{
    public function __construct(private readonly LeaveService $leave)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can(Permissions::LEAVE_VIEW), 403);

        $query = LeaveRequest::query()
            ->with(['employee', 'leaveType'])
            ->latest('start_date');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return LeaveRequestResource::collection($query->get());
    }

    public function store(StoreLeaveRequestRequest $request)
    {
        $data = $request->validated();

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $type = LeaveType::query()->findOrFail($data['leave_type_id']);

        $leaveRequest = $this->leave->apply(
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

    public function cancel(Request $request, LeaveRequest $leaveRequest)
    {
        abort_unless($request->user()->can(Permissions::LEAVE_MANAGE), 403);

        if ($leaveRequest->status !== LeaveRequest::STATUS_PENDING) {
            throw new ConflictHttpException('Only pending requests can be cancelled.');
        }

        $leaveRequest->update(['status' => LeaveRequest::STATUS_CANCELLED]);

        return new LeaveRequestResource($leaveRequest->load(['employee', 'leaveType']));
    }
}
