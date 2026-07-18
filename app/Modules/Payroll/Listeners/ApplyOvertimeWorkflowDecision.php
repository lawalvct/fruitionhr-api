<?php

namespace App\Modules\Payroll\Listeners;

use App\Core\Workflow\Events\WorkflowApproved;
use App\Core\Workflow\Events\WorkflowRejected;
use App\Modules\Payroll\Models\OvertimePayment;

/**
 * Bridges the workflow engine to overtime: final approval marks the record
 * approved (payable — either pulled into the next payroll run or paid
 * off-cycle); rejection marks it rejected. No-ops for other modules.
 */
class ApplyOvertimeWorkflowDecision
{
    public function approved(WorkflowApproved $event): void
    {
        $overtime = $this->resolve($event->request);

        if ($overtime !== null && $overtime->status === OvertimePayment::STATUS_PENDING) {
            $overtime->update(['status' => OvertimePayment::STATUS_APPROVED]);
        }
    }

    public function rejected(WorkflowRejected $event): void
    {
        $overtime = $this->resolve($event->request);

        if ($overtime !== null && $overtime->status === OvertimePayment::STATUS_PENDING) {
            $overtime->update(['status' => OvertimePayment::STATUS_REJECTED]);
        }
    }

    private function resolve($workflowRequest): ?OvertimePayment
    {
        if ($workflowRequest->module !== 'overtime') {
            return null;
        }

        return OvertimePayment::query()->find($workflowRequest->record_id);
    }
}
