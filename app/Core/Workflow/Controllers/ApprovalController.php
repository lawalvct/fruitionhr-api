<?php

namespace App\Core\Workflow\Controllers;

use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\Resources\WorkflowRequestResource;
use App\Core\Workflow\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ApprovalController extends Controller
{
    /**
     * Pending approvals where the current step's role is held by the user,
     * plus the user's own submitted requests.
     */
    public function index(Request $request, WorkflowService $workflow)
    {
        $user = $request->user();
        setPermissionsTeamId($user->tenant_id);
        $roleNames = $user->getRoleNames();
        $isOwner = $roleNames->contains('owner');

        $pendingForMe = WorkflowRequest::query()
            ->where('status', WorkflowRequest::STATUS_PENDING)
            // Owners see every pending step (they may always act)
            ->when(! $isOwner, fn ($q) => $q->whereHas(
                'currentStep',
                fn ($step) => $step->whereIn('approver_role', $roleNames),
            ))
            ->with(['currentStep', 'requester', 'record', 'actions.actor'])
            ->latest('submitted_at')
            ->get();

        $mine = WorkflowRequest::query()
            ->where('requested_by', $user->id)
            ->with(['currentStep', 'requester', 'actions.actor'])
            ->latest('submitted_at')
            ->limit(30)
            ->get();

        return response()->json([
            'data' => [
                'pending_for_me' => WorkflowRequestResource::collection($pendingForMe),
                'my_requests' => WorkflowRequestResource::collection($mine),
            ],
        ]);
    }

    public function approve(Request $request, WorkflowRequest $approval, WorkflowService $workflow)
    {
        return $this->act($request, $approval, $workflow, 'approve');
    }

    public function reject(Request $request, WorkflowRequest $approval, WorkflowService $workflow)
    {
        return $this->act($request, $approval, $workflow, 'reject');
    }

    public function return(Request $request, WorkflowRequest $approval, WorkflowService $workflow)
    {
        return $this->act($request, $approval, $workflow, 'return');
    }

    private function act(Request $request, WorkflowRequest $approval, WorkflowService $workflow, string $action)
    {
        $validated = $request->validate([
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $workflow->act($approval, $request->user(), $action, $validated['comments'] ?? null);

        return new WorkflowRequestResource($updated->load(['currentStep', 'requester', 'actions.actor']));
    }
}
