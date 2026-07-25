<?php

namespace App\Modules\Performance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'status' => $this->status, 'due_date' => $this->due_date?->toDateString(),
            'is_my_appraisal' => $this->employee?->user_id === $request->user()?->id,
            'employee' => $this->whenLoaded('employee', fn () => ['id' => $this->employee->id, 'name' => $this->employee->full_name]),
            'cycle' => $this->whenLoaded('cycle', fn () => ['id' => $this->cycle->id, 'name' => $this->cycle->name]),
            'template' => $this->whenLoaded('template', fn () => [
                'id' => $this->template->id, 'name' => $this->template->name,
                'items' => $this->template->items->map(fn ($item) => [
                    'id' => $item->id, 'weight' => $item->weight, 'is_mandatory' => $item->is_mandatory,
                    'kpi' => ['id' => $item->kpi->id, 'name' => $item->kpi->name, 'description' => $item->kpi->description, 'category' => $item->kpi->category?->name],
                ]),
            ]),
            'reviewers' => $this->whenLoaded('reviewers', fn () => $this->reviewers->map(fn ($reviewer) => [
                'id' => $reviewer->id, 'reviewer_type' => $reviewer->reviewer_type, 'weight' => $reviewer->weight,
                'status' => $reviewer->status, 'is_mine' => $reviewer->reviewer_user_id === $request->user()?->id,
                'user' => ['id' => $reviewer->user->id, 'name' => $reviewer->user->name],
            ])),
            'result' => $this->whenLoaded('result', fn () => $this->result ? [
                'id' => $this->result->id, 'final_score_basis_points' => $this->result->final_score_basis_points,
                'raw_score_basis_points' => $this->result->raw_score_basis_points,
                'grade' => $this->result->grade, 'status' => $this->result->status,
                'approved_at' => $this->result->approved_at?->toISOString(),
                'acknowledged_at' => $this->result->acknowledged_at?->toISOString(),
                'rejected_reason' => $this->result->rejected_reason,
                'is_my_result' => $this->employee?->user_id === $request->user()?->id,
                'outcomes' => $this->result->outcomes->map(fn ($outcome) => ['id' => $outcome->id, 'type' => $outcome->type, 'notes' => $outcome->notes]),
                'appeals' => $this->result->relationLoaded('appeals') ? $this->result->appeals->map(fn ($appeal) => [
                    'id' => $appeal->id, 'status' => $appeal->status, 'reason' => $appeal->reason, 'resolution_note' => $appeal->resolution_note,
                ]) : [],
            ] : null),
        ];
    }
}
