<?php

namespace App\Modules\Performance\Controllers;

use App\Modules\Performance\Models\PerformanceImprovementPlan;
use App\Modules\Performance\Models\PipMilestone;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Performance improvement plans with milestone tracking (build spec §5/§9 PIP Builder). */
class PipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PerformanceImprovementPlan::query()->with(['employee', 'milestones'])->latest();

        // Managers see every plan; everyone else only their own.
        if (! $request->user()->can(Permissions::PERFORMANCE_MANAGE)) {
            $query->whereHas('employee', fn ($inner) => $inner->where('user_id', $request->user()->id));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json(['data' => $query->get()->map(fn ($pip) => $this->payload($pip))]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_MANAGE), 403);
        $tenantId = app(CurrentTenant::class)->id();
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'reason' => ['required', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in([PerformanceImprovementPlan::STATUS_DRAFT, PerformanceImprovementPlan::STATUS_ACTIVE])],
            'starts_at' => ['required', 'date_format:Y-m-d'],
            'ends_at' => ['required', 'date_format:Y-m-d', 'after:starts_at'],
            'milestones' => ['sometimes', 'array'],
            'milestones.*.description' => ['required', 'string', 'max:1000'],
            'milestones.*.due_at' => ['required', 'date_format:Y-m-d'],
        ]);

        // Spec §6: every milestone due date must fall inside the PIP window.
        foreach ($data['milestones'] ?? [] as $milestone) {
            if ($milestone['due_at'] < $data['starts_at'] || $milestone['due_at'] > $data['ends_at']) {
                throw ValidationException::withMessages(['milestones' => 'Milestone due dates must fall within the PIP start and end dates.']);
            }
        }

        $milestones = $data['milestones'] ?? [];
        unset($data['milestones']);
        $pip = PerformanceImprovementPlan::query()->create([...$data, 'created_by' => $request->user()->id]);
        foreach ($milestones as $milestone) {
            $pip->milestones()->create($milestone);
        }

        return response()->json(['data' => $this->payload($pip->load(['employee', 'milestones']))], 201);
    }

    public function activate(Request $request, PerformanceImprovementPlan $pip): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_MANAGE), 403);
        abort_unless($pip->status === PerformanceImprovementPlan::STATUS_DRAFT, 409, 'Only a draft PIP can be activated.');

        $pip->update(['status' => PerformanceImprovementPlan::STATUS_ACTIVE]);

        return response()->json(['data' => $this->payload($pip->refresh()->load(['employee', 'milestones']))]);
    }

    public function close(Request $request, PerformanceImprovementPlan $pip): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_MANAGE), 403);
        abort_unless(in_array($pip->status, [PerformanceImprovementPlan::STATUS_DRAFT, PerformanceImprovementPlan::STATUS_ACTIVE], true), 409, 'This PIP is already closed.');
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['successful', 'unsuccessful'])],
            'outcome_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $pip->update([
            'status' => $data['outcome'] === 'successful'
                ? PerformanceImprovementPlan::STATUS_CLOSED_SUCCESSFUL
                : PerformanceImprovementPlan::STATUS_CLOSED_UNSUCCESSFUL,
            'outcome_note' => $data['outcome_note'] ?? null,
        ]);

        return response()->json(['data' => $this->payload($pip->refresh()->load(['employee', 'milestones']))]);
    }

    public function updateMilestone(Request $request, PipMilestone $milestone): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_MANAGE), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'completed', 'missed'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $milestone->update($data);

        return response()->json(['data' => $this->payload($milestone->plan()->with(['employee', 'milestones'])->firstOrFail())]);
    }

    private function payload(PerformanceImprovementPlan $pip): array
    {
        return [
            'id' => $pip->id,
            'employee' => ['id' => $pip->employee->id, 'name' => $pip->employee->full_name],
            'reason' => $pip->reason,
            'status' => $pip->status,
            'starts_at' => $pip->starts_at->toDateString(),
            'ends_at' => $pip->ends_at->toDateString(),
            'outcome_note' => $pip->outcome_note,
            'milestones' => $pip->milestones->map(fn ($milestone) => [
                'id' => $milestone->id,
                'description' => $milestone->description,
                'due_at' => $milestone->due_at->toDateString(),
                'status' => $milestone->status,
                'notes' => $milestone->notes,
            ]),
        ];
    }
}
