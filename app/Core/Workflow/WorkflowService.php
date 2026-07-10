<?php

namespace App\Core\Workflow;

use App\Core\Notifications\SystemNotification;
use App\Core\Workflow\Events\WorkflowApproved;
use App\Core\Workflow\Events\WorkflowRejected;
use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\Models\WorkflowStep;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WorkflowService
{
    /**
     * Submit a record into its module's active workflow.
     */
    public function submit(Model $record, string $module, User $requestedBy): WorkflowRequest
    {
        $definition = WorkflowDefinition::query()
            ->where('module', $module)
            ->where('is_active', true)
            ->with('steps')
            ->firstOrFail();

        $firstStep = $definition->steps->first();

        if ($firstStep === null) {
            throw new InvalidArgumentException("Workflow for [{$module}] has no steps.");
        }

        $request = WorkflowRequest::query()->create([
            'workflow_definition_id' => $definition->id,
            'module' => $module,
            'record_type' => $record->getMorphClass(),
            'record_id' => $record->getKey(),
            'requested_by' => $requestedBy->id,
            'current_step_id' => $firstStep->id,
            'status' => WorkflowRequest::STATUS_PENDING,
            'submitted_at' => now(),
        ]);

        $this->notifyApprovers($firstStep, $request);

        return $request;
    }

    /**
     * Approve / reject / return the current step.
     */
    public function act(WorkflowRequest $request, User $actor, string $action, ?string $comments = null): WorkflowRequest
    {
        if (! in_array($action, ['approve', 'reject', 'return'], true)) {
            throw new InvalidArgumentException("Unknown workflow action [{$action}].");
        }

        if (! $request->isPending()) {
            throw new ConflictHttpException('This request has already been completed.');
        }

        $step = $request->currentStep;

        if ($step === null || ! $this->canActOn($actor, $step)) {
            throw new AccessDeniedHttpException('You are not an approver for the current step.');
        }

        return DB::transaction(function () use ($request, $actor, $action, $comments, $step): WorkflowRequest {
            $request->actions()->create([
                'workflow_step_id' => $step->id,
                'action_by' => $actor->id,
                'action' => $action,
                'comments' => $comments,
            ]);

            match ($action) {
                'approve' => $this->advance($request, $step),
                'reject' => $this->finalise($request, WorkflowRequest::STATUS_REJECTED),
                'return' => $this->finalise($request, WorkflowRequest::STATUS_RETURNED),
            };

            return $request->refresh();
        });
    }

    public function canActOn(User $user, WorkflowStep $step): bool
    {
        setPermissionsTeamId($step->tenant_id);

        // Owners can always act — small companies often have no separate
        // manager/HR users yet.
        return $user->hasRole($step->approver_role) || $user->hasRole('owner');
    }

    private function advance(WorkflowRequest $request, WorkflowStep $currentStep): void
    {
        $nextStep = WorkflowStep::query()
            ->where('workflow_definition_id', $request->workflow_definition_id)
            ->where('step_order', '>', $currentStep->step_order)
            ->orderBy('step_order')
            ->first();

        if ($nextStep === null) {
            $this->finalise($request, WorkflowRequest::STATUS_APPROVED);

            return;
        }

        $request->update(['current_step_id' => $nextStep->id]);
        $this->notifyApprovers($nextStep, $request);
    }

    private function finalise(WorkflowRequest $request, string $status): void
    {
        $request->update([
            'status' => $status,
            'current_step_id' => null,
            'completed_at' => now(),
        ]);

        $request->requester->notify(new SystemNotification(
            title: ucfirst($request->module).' request '.$status,
            body: "Your {$request->module} request has been {$status}.",
            actionUrl: '/approvals',
            type: $status === WorkflowRequest::STATUS_APPROVED ? 'success' : 'warning',
        ));

        match ($status) {
            WorkflowRequest::STATUS_APPROVED => WorkflowApproved::dispatch($request),
            WorkflowRequest::STATUS_REJECTED => WorkflowRejected::dispatch($request),
            default => null,
        };
    }

    private function notifyApprovers(WorkflowStep $step, WorkflowRequest $request): void
    {
        $tenantId = app(CurrentTenant::class)->id();
        setPermissionsTeamId($tenantId);

        $approvers = User::query()
            ->where('tenant_id', $tenantId)
            ->role($step->approver_role)
            ->get();

        Notification::send($approvers, new SystemNotification(
            title: 'Approval needed: '.$request->module,
            body: "A {$request->module} request is waiting for your approval ({$step->step_name}).",
            actionUrl: '/approvals',
            type: 'info',
        ));
    }
}
