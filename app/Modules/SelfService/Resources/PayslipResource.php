<?php

namespace App\Modules\SelfService\Resources;

use App\Modules\Payroll\Models\PayrollRunEmployee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PayrollRunEmployee
 */
class PayslipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_run_id' => $this->payroll_run_id,
            'period' => $this->run->period,
            'status' => $this->run->status,
            'gross' => $this->gross,
            'total_deductions' => $this->total_deductions,
            'net' => $this->net,
            'download_url' => "/api/v1/self/payslips/{$this->id}/download",
        ];
    }
}
