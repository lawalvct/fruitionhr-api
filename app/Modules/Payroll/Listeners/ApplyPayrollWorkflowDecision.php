<?php

namespace App\Modules\Payroll\Listeners;

use App\Core\Workflow\Events\WorkflowApproved;
use App\Core\Workflow\Events\WorkflowRejected;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollRunService;

/**
 * Bridges the workflow engine to payroll: final approval moves the run to
 * approved (ready to lock); rejection returns it to review. No-ops for other
 * modules.
 */
class ApplyPayrollWorkflowDecision
{
    public function __construct(private readonly PayrollRunService $runService)
    {
    }

    public function approved(WorkflowApproved $event): void
    {
        $run = $this->resolve($event->request);

        if ($run !== null) {
            $this->runService->markApproved($run);
        }
    }

    public function rejected(WorkflowRejected $event): void
    {
        $run = $this->resolve($event->request);

        if ($run !== null) {
            $this->runService->markReturnedToReview($run);
        }
    }

    private function resolve($workflowRequest): ?PayrollRun
    {
        if ($workflowRequest->module !== 'payroll') {
            return null;
        }

        return PayrollRun::query()->find($workflowRequest->record_id);
    }
}
