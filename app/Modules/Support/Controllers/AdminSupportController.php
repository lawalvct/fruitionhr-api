<?php

namespace App\Modules\Support\Controllers;

use App\Models\User;
use App\Modules\Admin\Services\PlatformActivityService;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;
use App\Modules\Support\Resources\SupportTicketResource;
use App\Modules\Support\Services\SupportTicketService;
use App\Support\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * The platform support queue: every tenant's tickets in one place.
 *
 * Reads deliberately cross the tenant boundary — that is the whole job — and
 * the super-admin middleware on the route group is what makes it safe.
 */
class AdminSupportController extends Controller
{
    public function __construct(private readonly SupportTicketService $tickets) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
            'tenant_id' => ['nullable', 'integer', 'min:1'],
            'assigned_to' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return SupportTicketResource::collection(
            $this->tickets->paginateForPlatform($filters)
                ->through(fn (SupportTicket $ticket) => new SupportTicketResource($ticket, forAgent: true))
        )->additional(['summary' => $this->tickets->platformSummary()]);
    }

    public function show(int $ticket): SupportTicketResource
    {
        return new SupportTicketResource($this->loadThread($this->find($ticket)), forAgent: true);
    }

    /** Reply to the customer, or leave an internal note for other agents. */
    public function reply(
        Request $request,
        int $ticket,
        PlatformActivityService $activity,
    ): SupportTicketResource {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:5000'],
            'internal' => ['nullable', 'boolean'],
        ]);

        /** @var User $agent */
        $agent = $request->user();
        $model = $this->find($ticket);
        $internal = (bool) ($validated['internal'] ?? false);

        $this->tickets->reply(
            ticket: $model,
            author: $agent,
            body: $validated['body'],
            authorType: SupportTicketMessage::AUTHOR_AGENT,
            internal: $internal,
        );

        $activity->record(
            request: $request,
            action: $internal ? 'support.note_added' : 'support.replied',
            subjectType: 'support_ticket',
            subjectId: $model->id,
            subjectLabel: $model->reference.' — '.$model->subject,
        );

        return new SupportTicketResource($this->loadThread($model->refresh()), forAgent: true);
    }

    public function updateStatus(
        Request $request,
        int $ticket,
        PlatformActivityService $activity,
    ): SupportTicketResource {
        $validated = $request->validate([
            'status' => ['required', Rule::in(SupportTicket::STATUSES)],
        ]);

        $model = $this->find($ticket);
        $before = $model->status;
        $updated = $this->tickets->updateStatus($model, $validated['status']);

        $activity->record(
            request: $request,
            action: 'support.status_changed',
            subjectType: 'support_ticket',
            subjectId: $updated->id,
            subjectLabel: $updated->reference,
            before: ['status' => $before],
            after: ['status' => $updated->status],
        );

        return new SupportTicketResource($this->loadThread($updated), forAgent: true);
    }

    public function assign(Request $request, int $ticket): SupportTicketResource
    {
        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'min:1'],
        ]);

        $updated = $this->tickets->assign($this->find($ticket), $validated['assigned_to'] ?? null);

        return new SupportTicketResource($this->loadThread($updated), forAgent: true);
    }

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->tickets->platformSummary()]);
    }

    private function find(int $ticketId): SupportTicket
    {
        return SupportTicket::query()
            ->withoutGlobalScope(TenantScope::class)
            ->findOrFail($ticketId);
    }

    /** Agents see the whole thread, internal notes included. */
    private function loadThread(SupportTicket $ticket): SupportTicket
    {
        return $ticket->load([
            'company:id,name,slug',
            'openedBy:id,name,email',
            'assignee:id,name',
            'messages' => fn ($query) => $query
                ->withoutGlobalScope(TenantScope::class)
                ->with('author:id,name'),
        ]);
    }
}
