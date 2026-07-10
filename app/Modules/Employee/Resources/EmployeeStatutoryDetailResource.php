<?php

namespace App\Modules\Employee\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeStatutoryDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tax_id' => $this->tax_id,
            'pension_pin' => $this->pension_pin,
            'pension_fund_administrator' => $this->pension_fund_administrator,
            'nhf_number' => $this->nhf_number,
        ];
    }
}
