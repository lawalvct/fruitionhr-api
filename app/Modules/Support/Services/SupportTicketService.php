<?php

namespace App\Modules\Support\Services;

use App\Models\User;
use App\Modules\Admin\Services\PlatformAdminNotifier;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;
use App\Modules\Support\Notifications\SupportTicketRepliedNotification;
use App\Support\Authorization\PlatformAbilities;
use App\Support\Tenancy\TenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The support desk.
 *
 * Both surfaces share this service, but they ask different questions of it:
 * a company only ever sees its own tickets and never internal notes, while the
 * platform works a queue across every tenant.
 */
class SupportTicketService
{
    public function __construct(
        private readonly PlatformAdminNotifier $platformNotifier,
    ) {
    }

    /**
     * Raise a ticket with its opening message.
     *
     * @param  array{subject: string, category?: string, priority?: string, body: string}  $data
     */
    public function open(User $author, int $tenantId, array $data): SupportTicket
    {
        return DB::transaction(function () use ($author, $tenantId, $data): SupportTicket {
            $ticket = new SupportTicket;
            $ticket->forceFill([
                'tenant_id' => $tenantId,
                'reference' => $this->nextReference(),
                'subject' => trim($data['subject']),
                'category' => $data['category'] ?? 'other',
                'priority' => $data['priority'] ?? 'normal',
                'status' => SupportTicket::STATUS_OPEN,
                'opened_by' => $author->id,
                'last_customer_reply_at' => now(),
            ])->save();

            $this->addMessage($ticket, $author, $data['body'], SupportTicketMessage::AUTHOR_CUSTOMER);
            $this->notifyPlatform($ticket, 'raised a ticket');

            return $ticket->refresh();
        });
    }

    /**
     * Post a reply.
     *
     * An agent replying is what moves a ticket along: a customer waiting is
     * put back in the queue, and an agent's answer hands it back to them.
     */
    public function reply(
        SupportTicket $ticket,
        User $author,
        string $body,
        string $authorType,
        bool $internal = false,
    ): SupportTicketMessage {
        if ($ticket->isClosed()) {
            throw ValidationException::withMessages([
                'body' => 'This ticket is closed. Open a new one if you still need help.',
            ]);
        }

        return DB::transaction(function () use ($ticket, $author, $body, $authorType, $internal): SupportTicketMessage {
            $message = $this->addMessage($ticket, $author, $body, $authorType, $internal);

            // An internal note is a private aside — it must not change what the
            // customer sees about who is waiting on whom.
            if ($internal) {
                return $message;
            }

            if ($authorType === SupportTicketMessage::AUTHOR_AGENT) {
                $ticket->forceFill([
                    'last_agent_reply_at' => now(),
                    'first_responded_at' => $ticket->first_responded_at ?? now(),
                    'status' => $ticket->status === SupportTicket::STATUS_RESOLVED
                        ? SupportTicket::STATUS_RESOLVED
                        : SupportTicket::STATUS_WAITING_ON_CUSTOMER,
                ])->save();

                $this->notifyCustomer($ticket, $message);
            } else {
                $ticket->forceFill([
                    'last_customer_reply_at' => now(),
                    // A customer replying to a resolved ticket reopens it —
                    // they clearly do not consider it finished.
                    'status' => SupportTicket::STATUS_OPEN,
                ])->save();

                $this->notifyPlatform($ticket, 'replied');
            }

            return $message;
        });
    }

    /** @param  array<string, mixed>  $filters */
    public function paginateForTenant(int $tenantId, array $filters): LengthAwarePaginator
    {
        return $this->baseQuery($filters)
            ->where('tenant_id', $tenantId)
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->appends($filters);
    }

    /**
     * The platform queue, across every tenant.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForPlatform(array $filters): LengthAwarePaginator
    {
        $query = $this->baseQuery($filters)->with('company:id,name,slug');

        if (($filters['tenant_id'] ?? null) !== null) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        if (($filters['assigned_to'] ?? null) !== null) {
            $query->where('assigned_to', (int) $filters['assigned_to']);
        }

        // Unresolved work first, then urgency, then age.
        return $query
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->appends($filters);
    }

    public function updateStatus(SupportTicket $ticket, string $status): SupportTicket
    {
        if (! in_array($status, SupportTicket::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'That is not a valid ticket status.']);
        }

        $ticket->forceFill([
            'status' => $status,
            'resolved_at' => $status === SupportTicket::STATUS_RESOLVED ? ($ticket->resolved_at ?? now()) : null,
            'closed_at' => $status === SupportTicket::STATUS_CLOSED ? ($ticket->closed_at ?? now()) : null,
        ])->save();

        return $ticket->refresh();
    }

    public function assign(SupportTicket $ticket, ?int $agentId): SupportTicket
    {
        if ($agentId !== null) {
            $isAdmin = User::query()->whereKey($agentId)->where('is_super_admin', true)->exists();

            if (! $isAdmin) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'Tickets can only be assigned to platform administrators.',
                ]);
            }
        }

        $ticket->forceFill([
            'assigned_to' => $agentId,
            // Picking a ticket up is the moment it stops being untouched.
            'status' => $agentId !== null && $ticket->status === SupportTicket::STATUS_OPEN
                ? SupportTicket::STATUS_IN_PROGRESS
                : $ticket->status,
        ])->save();

        return $ticket->refresh();
    }

    /** @return array<string, int> */
    public function platformSummary(): array
    {
        $base = fn (): Builder => SupportTicket::query()->withoutGlobalScope(TenantScope::class);

        return [
            'open' => $base()->where('status', SupportTicket::STATUS_OPEN)->count(),
            'in_progress' => $base()->where('status', SupportTicket::STATUS_IN_PROGRESS)->count(),
            'waiting_on_customer' => $base()->where('status', SupportTicket::STATUS_WAITING_ON_CUSTOMER)->count(),
            'resolved' => $base()->where('status', SupportTicket::STATUS_RESOLVED)->count(),
            'unassigned' => $base()
                ->whereNull('assigned_to')
                ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_IN_PROGRESS])
                ->count(),
        ];
    }

    /** @param  array<string, mixed>  $filters */
    private function baseQuery(array $filters): Builder
    {
        $query = SupportTicket::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with(['openedBy:id,name,email', 'assignee:id,name'])
            ->withCount(['messages' => fn ($q) => $q
                ->withoutGlobalScope(TenantScope::class)
                ->where('is_internal', false)]);

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['priority'] ?? null) !== null) {
            $query->where('priority', $filters['priority']);
        }

        if (($filters['search'] ?? null) !== null) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('subject', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%");
            });
        }

        return $query->orderByRaw($this->queueOrder())->orderByDesc('id');
    }

    /**
     * Work that needs us comes first, then urgency, then the longest wait —
     * a simple ordering an agent can trust without reading filters.
     *
     * Written as CASE rather than MySQL's FIELD() so the same query runs on
     * SQLite, which the test suite uses.
     */
    private function queueOrder(): string
    {
        $status = $this->rank('status', SupportTicket::STATUSES);
        $priority = $this->rank('priority', ['urgent', 'high', 'normal', 'low']);

        return "{$status}, {$priority}, created_at ASC";
    }

    /**
     * Builds a portable "order by this list" expression.
     *
     * @param  list<string>  $values
     */
    private function rank(string $column, array $values): string
    {
        $cases = '';

        foreach (array_values($values) as $index => $value) {
            // Values are class constants, never user input.
            $cases .= " WHEN '{$value}' THEN {$index}";
        }

        return "CASE {$column}{$cases} ELSE ".count($values).' END';
    }

    private function addMessage(
        SupportTicket $ticket,
        User $author,
        string $body,
        string $authorType,
        bool $internal = false,
    ): SupportTicketMessage {
        $message = new SupportTicketMessage;
        $message->forceFill([
            'tenant_id' => $ticket->tenant_id,
            'support_ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'author_type' => $authorType,
            'body' => trim($body),
            'is_internal' => $internal,
        ])->save();

        return $message->refresh();
    }

    /**
     * Put the ticket in front of the support team.
     *
     * Without this the queue is silent: a customer waits, and nobody on our
     * side knows unless somebody happens to open the admin console.
     */
    private function notifyPlatform(SupportTicket $ticket, string $what): void
    {
        $company = $ticket->company()->withoutGlobalScope(TenantScope::class)->first();

        $this->platformNotifier->notify(
            ability: PlatformAbilities::SUPPORT,
            title: $what === 'raised a ticket' ? 'New support ticket' : 'Customer replied',
            body: sprintf(
                '%s %s: %s (%s)',
                $company?->name ?? 'A company',
                $what,
                $ticket->subject,
                $ticket->reference,
            ),
            actionUrl: '/support',
            type: $ticket->priority === 'urgent' ? 'danger' : 'info',
        );
    }

    private function notifyCustomer(SupportTicket $ticket, SupportTicketMessage $message): void
    {
        $recipient = $ticket->opened_by === null
            ? null
            : User::query()->find($ticket->opened_by);

        $recipient?->notify(new SupportTicketRepliedNotification($ticket, $message));
    }

    /** Sequential and quotable: TKT-000001. */
    private function nextReference(): string
    {
        $lastId = (int) SupportTicket::query()
            ->withoutGlobalScope(TenantScope::class)
            ->max('id');

        return 'TKT-'.str_pad((string) ($lastId + 1), 6, '0', STR_PAD_LEFT);
    }
}
