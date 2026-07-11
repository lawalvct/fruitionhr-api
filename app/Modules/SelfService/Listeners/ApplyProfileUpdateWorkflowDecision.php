<?php

namespace App\Modules\SelfService\Listeners;

use App\Core\Workflow\Events\WorkflowApproved;
use App\Core\Workflow\Events\WorkflowRejected;
use App\Modules\SelfService\Models\ProfileUpdateRequest;
use App\Modules\SelfService\Services\ProfileUpdateService;

class ApplyProfileUpdateWorkflowDecision
{
    public function __construct(private readonly ProfileUpdateService $profileUpdates)
    {
    }

    public function approved(WorkflowApproved $event): void
    {
        if ($event->request->module !== 'profile_update') {
            return;
        }

        $record = $event->request->record;

        if ($record instanceof ProfileUpdateRequest) {
            $this->profileUpdates->markApproved($record);
        }
    }

    public function rejected(WorkflowRejected $event): void
    {
        if ($event->request->module !== 'profile_update') {
            return;
        }

        $record = $event->request->record;

        if ($record instanceof ProfileUpdateRequest) {
            $this->profileUpdates->markRejected($record);
        }
    }
}
