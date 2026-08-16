<?php

namespace App\Modules\Billing\Resources;

use App\Modules\Billing\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    /** @var array<string, mixed>|null */
    private ?array $quote = null;

    /**
     * Attach what a particular tenant would pay for this plan.
     *
     * Deliberately a setter rather than a constructor argument: Laravel's
     * Collection::mapInto() calls `new static($model, $key)`, so a second
     * constructor parameter gets handed the array key and blows up when the
     * resource is used with ::collection() over plain models.
     *
     * @param  array<string, mixed>  $quote
     */
    public function withQuote(array $quote): static
    {
        $this->quote = $quote;

        return $this;
    }

    /**
     * The plan's own fields are always present, including the nullable ones —
     * a missing `max_employees` and a null one mean different things to a
     * client ("unknown" vs "unlimited"), and filtering nulls conflates them.
     * Only the two contextual extras are conditional.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price_per_employee' => $this->price_per_employee, // kobo
            'billing_interval' => $this->billing_interval,
            'min_employees' => $this->min_employees,
            'max_employees' => $this->max_employees, // null = unlimited
            'trial_days' => $this->trial_days,
            'features' => $this->features ?? [],
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            // Only present when the caller asked for a count.
            'subscriptions_count' => $this->whenCounted('subscriptions'),
            // What this specific tenant would pay today, when quoted.
            'quote' => $this->when($this->quote !== null, $this->quote),
        ];
    }
}
