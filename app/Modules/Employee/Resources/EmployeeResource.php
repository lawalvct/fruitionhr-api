<?php

namespace App\Modules\Employee\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'user_id' => $this->user_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'official_email' => $this->official_email,
            'personal_email' => $this->personal_email,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'marital_status' => $this->marital_status,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'photo_path' => $this->photo_path,
            // Keep this relative so the SPA uses its same-origin /api rewrite and session cookies.
            'photo_url' => $this->photo_path ? "/api/v1/employees/{$this->id}/photo" : null,
            'employment_status' => $this->employment_status,
            'hired_at' => $this->hired_at?->format('Y-m-d'),
            'exited_at' => $this->exited_at?->format('Y-m-d'),
            'current_assignment' => new EmployeeAssignmentResource($this->whenLoaded('currentAssignment')),
            'current_basic_salary' => $this->whenLoaded('currentSalary', fn () => $this->currentSalary?->basic_salary),
            'employment_records' => EmployeeAssignmentResource::collection($this->whenLoaded('employmentRecords')),
            'contacts' => EmployeeContactResource::collection($this->whenLoaded('contacts')),
            'bank_accounts' => EmployeeBankAccountResource::collection($this->whenLoaded('bankAccounts')),
            'statutory_details' => new EmployeeStatutoryDetailResource($this->whenLoaded('statutoryDetails')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
