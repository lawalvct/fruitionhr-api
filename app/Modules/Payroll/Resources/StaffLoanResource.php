<?php

namespace App\Modules\Payroll\Resources;

use App\Modules\Payroll\Models\StaffLoan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffLoan
 */
class StaffLoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'name' => $this->employee->full_name,
                'number' => $this->employee->employee_number,
            ]),
            'employee_id' => $this->employee_id,
            'type' => $this->type,
            'principal' => $this->principal,               // kobo
            'months' => $this->months,
            'monthly_installment' => $this->monthly_installment, // kobo
            'balance' => $this->balance,                   // kobo
            'start_period' => $this->start_period,
            'next_deduction_override' => $this->next_deduction_override, // kobo | null
            'scheduled_deduction' => $this->status === StaffLoan::STATUS_ACTIVE ? $this->scheduledDeduction() : null,
            'status' => $this->status,
            'reason' => $this->reason,
            'is_editable' => $this->isEditable(),
            'disbursed_at' => $this->disbursed_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
