<?php

namespace App\Modules\Employee\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ] : null),
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'position_id' => $this->position_id,
            'position' => $this->whenLoaded('position', fn () => $this->position ? [
                'id' => $this->position->id,
                'title' => $this->position->title,
            ] : null),
            'job_grade_id' => $this->job_grade_id,
            'job_grade' => $this->whenLoaded('jobGrade', fn () => $this->jobGrade ? [
                'id' => $this->jobGrade->id,
                'name' => $this->jobGrade->name,
            ] : null),
            'employment_type_id' => $this->employment_type_id,
            'employment_type' => $this->whenLoaded('employmentType', fn () => $this->employmentType ? [
                'id' => $this->employmentType->id,
                'name' => $this->employmentType->name,
            ] : null),
            'supervisor_id' => $this->supervisor_id,
            'supervisor' => $this->whenLoaded('supervisor', fn () => $this->supervisor ? [
                'id' => $this->supervisor->id,
                'employee_number' => $this->supervisor->employee_number,
                'name' => $this->supervisor->full_name,
            ] : null),
            'effective_from' => $this->effective_from?->format('Y-m-d'),
            'effective_to' => $this->effective_to?->format('Y-m-d'),
            'is_current' => $this->is_current,
        ];
    }
}
