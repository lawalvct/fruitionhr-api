<?php

namespace App\Core\Workflow\Resources;

use App\Core\Workflow\Models\WorkflowRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowRequest
 */
class WorkflowRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module' => $this->module,
            'status' => $this->status,
            'record_type' => $this->record_type,
            'record_id' => $this->record_id,
            'record_summary' => $this->recordSummary(),
            'requested_by' => [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ],
            'current_step' => $this->whenLoaded('currentStep', fn () => $this->currentStep === null ? null : [
                'id' => $this->currentStep->id,
                'name' => $this->currentStep->step_name,
                'approver_role' => $this->currentStep->approver_role,
            ]),
            'actions' => $this->whenLoaded('actions', fn () => $this->actions->map(fn ($action) => [
                'id' => $action->id,
                'action' => $action->action,
                'comments' => $action->comments,
                'by' => $action->actor->name,
                'at' => $action->created_at?->toISOString(),
            ])),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }

    private function recordSummary(): string
    {
        $record = $this->resource->relationLoaded('record') ? $this->record : null;
        $fallback = ucfirst(str_replace('_', ' ', $this->module)).' request #'.$this->record_id;

        if ($record === null) {
            return $fallback;
        }

        // Records may expose a human-readable label for approvals lists.
        if (method_exists($record, 'workflowSummary')) {
            return $record->workflowSummary();
        }

        // Otherwise use a known column if present (guard strict-mode missing
        // attribute errors by checking loaded attributes, not accessors).
        $attributes = $record->getAttributes();
        foreach (['name', 'title'] as $column) {
            if (! empty($attributes[$column]) && is_string($attributes[$column])) {
                return $attributes[$column];
            }
        }

        return $fallback;
    }
}
