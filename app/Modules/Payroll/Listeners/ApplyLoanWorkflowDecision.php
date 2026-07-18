<?php

namespace App\Modules\Payroll\Listeners;

use App\Core\Workflow\Events\WorkflowApproved;
use App\Core\Workflow\Events\WorkflowRejected;
use App\Modules\Payroll\Models\StaffLoan;

/**
 * Bridges the workflow engine to loans/advances: final approval activates the
 * loan (recoverable from the coming payroll); rejection marks it rejected.
 * No-ops for other modules.
 */
class ApplyLoanWorkflowDecision
{
    public function approved(WorkflowApproved $event): void
    {
        $loan = $this->resolve($event->request);

        if ($loan !== null && $loan->status === StaffLoan::STATUS_PENDING) {
            $loan->update(['status' => StaffLoan::STATUS_ACTIVE, 'disbursed_at' => now()]);
        }
    }

    public function rejected(WorkflowRejected $event): void
    {
        $loan = $this->resolve($event->request);

        if ($loan !== null && $loan->status === StaffLoan::STATUS_PENDING) {
            $loan->update(['status' => StaffLoan::STATUS_REJECTED]);
        }
    }

    private function resolve($workflowRequest): ?StaffLoan
    {
        if ($workflowRequest->module !== 'loan') {
            return null;
        }

        return StaffLoan::query()->find($workflowRequest->record_id);
    }
}
