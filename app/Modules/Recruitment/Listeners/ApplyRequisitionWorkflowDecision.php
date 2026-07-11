<?php

namespace App\Modules\Recruitment\Listeners;

use App\Core\Workflow\Events\WorkflowApproved;
use App\Core\Workflow\Events\WorkflowRejected;
use App\Modules\Recruitment\Models\ManpowerRequisition;
use App\Modules\Recruitment\Services\RecruitmentService;

class ApplyRequisitionWorkflowDecision
{
    public function __construct(private readonly RecruitmentService $recruitment) {}

    public function approved(WorkflowApproved $event): void
    {
        if ($requisition = $this->resolve($event->request)) {
            $this->recruitment->markRequisition($requisition, ManpowerRequisition::STATUS_APPROVED);
        }
    }

    public function rejected(WorkflowRejected $event): void
    {
        if ($requisition = $this->resolve($event->request)) {
            $this->recruitment->markRequisition($requisition, ManpowerRequisition::STATUS_REJECTED);
        }
    }

    private function resolve($workflowRequest): ?ManpowerRequisition
    {
        if ($workflowRequest->module !== 'recruitment_requisition') {
            return null;
        }

        return ManpowerRequisition::query()->find($workflowRequest->record_id);
    }
}
