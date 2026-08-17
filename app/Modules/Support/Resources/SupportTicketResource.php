<?php

namespace App\Modules\Support\Resources;

use App\Modules\Support\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupportTicket */
class SupportTicketResource extends JsonResource
{
    public function __construct($resource, private readonly bool $forAgent = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'subject' => $this->subject,
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->status,
            'is_closed' => $this->isClosed(),
            'message_count' => (int) ($this->messages_count ?? 0),
            'opened_by' => $this->whenLoaded('openedBy', fn () => $this->openedBy === null ? null : [
                'id' => $this->openedBy->id,
                'name' => $this->openedBy->name,
                'email' => $this->openedBy->email,
            ]),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee === null ? null : [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ]),
            // The owning company only matters to the platform console.
            'company' => $this->when($this->forAgent, fn () => $this->relationLoaded('company') && $this->company !== null
                ? ['id' => $this->company->id, 'name' => $this->company->name]
                : null),
            'last_customer_reply_at' => $this->last_customer_reply_at?->toIso8601String(),
            'last_agent_reply_at' => $this->last_agent_reply_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'messages' => $this->whenLoaded(
                'messages',
                fn () => SupportTicketMessageResource::collection($this->messages)
            ),
        ];
    }
}
