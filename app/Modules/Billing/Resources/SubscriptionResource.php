<?php

namespace App\Modules\Billing\Resources;

use App\Modules\Billing\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'on_trial' => $this->onTrial(),
            'is_usable' => $this->isUsable(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'current_period_start' => $this->current_period_start?->toIso8601String(),
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'employee_count' => $this->employee_count,
            'amount' => $this->amount, // kobo
            'plan' => $this->whenLoaded('plan', fn () => new PlanResource($this->plan)),
        ];
    }
}
