<?php

namespace App\Modules\Leave\Listeners;

use App\Core\Workflow\Events\WorkflowApproved;
use App\Core\Workflow\Events\WorkflowRejected;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveService;

/**
 * Bridges the generic workflow engine to the Leave module: when a leave
 * request is finally approved, the balance is debited; when rejected, the
 * request is marked rejected. Runs synchronously inside tenant context.
 */
class ApplyLeaveWorkflowDecision
{
    public function __construct(private readonly LeaveService $leave)
    {
    }

    public function approved(WorkflowApproved $event): void
    {
        $request = $this->resolveLeaveRequest($event->request);

        if ($request !== null) {
            $this->leave->markApproved($request);
        }
    }

    public function rejected(WorkflowRejected $event): void
    {
        $request = $this->resolveLeaveRequest($event->request);

        if ($request !== null) {
            $this->leave->markRejected($request);
        }
    }

    private function resolveLeaveRequest($workflowRequest): ?LeaveRequest
    {
        if ($workflowRequest->module !== 'leave') {
            return null;
        }

        return LeaveRequest::query()->find($workflowRequest->record_id);
    }
}
