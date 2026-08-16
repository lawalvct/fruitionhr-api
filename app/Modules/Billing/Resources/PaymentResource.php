<?php

namespace App\Modules\Billing\Resources;

use App\Modules\Billing\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never exposes gateway_response — it can carry customer card metadata and is
 * for our logs, not the client.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'gateway' => $this->gateway,
            'amount' => $this->amount, // kobo
            'currency' => $this->currency,
            'status' => $this->status,
            'employee_count' => $this->employee_count,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
