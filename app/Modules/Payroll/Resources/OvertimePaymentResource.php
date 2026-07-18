<?php

namespace App\Modules\Payroll\Resources;

use App\Modules\Payroll\Models\OvertimePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OvertimePayment
 */
class OvertimePaymentResource extends JsonResource
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
            'period' => $this->period,
            'work_date' => $this->work_date?->toDateString(),
            'source' => $this->source,
            'pay_type' => $this->pay_type,
            'hours' => $this->hours,
            'multiplier' => $this->multiplier,
            'hourly_rate' => $this->hourly_rate, // kobo/hour
            'amount' => $this->amount,           // kobo
            'disbursement_mode' => $this->disbursement_mode,
            'status' => $this->status,
            'reason' => $this->reason,
            'payroll_run_id' => $this->payroll_run_id,
            'paid_at' => $this->paid_at?->toISOString(),
            'is_editable' => $this->isEditable(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
