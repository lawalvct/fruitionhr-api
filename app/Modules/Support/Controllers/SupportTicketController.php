<?php

namespace App\Modules\Support\Controllers;

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;
use App\Modules\Support\Resources\SupportTicketResource;
use App\Modules\Support\Services\SupportTicketService;
use App\Support\Tenancy\CurrentTenant;
use App\Support\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * A company's own support tickets.
 *
 * Every read is pinned to the current tenant, and internal agent notes are
 * filtered out of the thread — a customer must never see what we write about
 * them internally.
 */
class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketService $tickets) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(SupportTicket::STATUSES)],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return SupportTicketResource::collection(
            $this->tickets->paginateForTenant($this->tenantId(), $filters)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'min:4', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'category' => ['nullable', Rule::in(SupportTicket::CATEGORIES)],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $ticket = $this->tickets->open($user, $this->tenantId(), $validated);

        return (new SupportTicketResource($this->loadThread($ticket)))
            ->additional(['message' => 'Ticket '.$ticket->reference.' created. We will be in touch.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(int $ticket): SupportTicketResource
    {
        return new SupportTicketResource($this->loadThread($this->findForTenant($ticket)));
    }

    public function reply(Request $request, int $ticket): SupportTicketResource
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:5000'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $model = $this->findForTenant($ticket);

        $this->tickets->reply(
            ticket: $model,
            author: $user,
            body: $validated['body'],
            authorType: SupportTicketMessage::AUTHOR_CUSTOMER,
        );

        return new SupportTicketResource($this->loadThread($model->refresh()));
    }

    /** A customer can close their own ticket once they are satisfied. */
    public function close(int $ticket): SupportTicketResource
    {
        $model = $this->tickets->updateStatus(
            $this->findForTenant($ticket),
            SupportTicket::STATUS_CLOSED,
        );

        return new SupportTicketResource($this->loadThread($model));
    }

    private function findForTenant(int $ticketId): SupportTicket
    {
        return SupportTicket::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($ticketId);
    }

    /** Loads the thread with internal notes stripped. */
    private function loadThread(SupportTicket $ticket): SupportTicket
    {
        return $ticket->load([
            'openedBy:id,name,email',
            'assignee:id,name',
            'messages' => fn ($query) => $query
                ->withoutGlobalScope(TenantScope::class)
                ->where('is_internal', false)
                ->with('author:id,name'),
        ]);
    }

    private function tenantId(): int
    {
        return (int) app(CurrentTenant::class)->id();
    }
}
