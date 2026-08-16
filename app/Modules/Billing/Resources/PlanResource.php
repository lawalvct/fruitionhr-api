<?php

namespace App\Modules\Billing\Resources;

use App\Modules\Billing\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    /** @param array<string, mixed>|null $quote */
    public function __construct($resource, private readonly ?array $quote = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price_per_employee' => $this->price_per_employee, // kobo
            'billing_interval' => $this->billing_interval,
            'min_employees' => $this->min_employees,
            'max_employees' => $this->max_employees,
            'trial_days' => $this->trial_days,
            'features' => $this->features ?? [],
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            // What this specific tenant would pay today, when asked for.
            'quote' => $this->quote,
        ], static fn ($value) => $value !== null);
    }
}
