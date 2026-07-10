<?php

namespace App\Modules\Leave\Controllers;

use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Requests\LeaveTypeRequest;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LeaveTypeController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can(Permissions::LEAVE_VIEW), 403);

        $types = LeaveType::query()
            ->with(['policy' => fn ($q) => $q->latest('id')])
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveType $type) => $this->present($type));

        return response()->json(['data' => $types]);
    }

    public function store(LeaveTypeRequest $request)
    {
        $data = $request->validated();

        $type = LeaveType::query()->create([
            ...collect($data)->only(['name', 'code', 'is_paid', 'requires_document', 'is_active'])->all(),
            'created_by' => $request->user()->id,
        ]);

        $this->syncPolicy($type, $data, $request->user()->id);

        return response()->json(['data' => $this->present($type->fresh('policy'))], 201);
    }

    public function update(LeaveTypeRequest $request, LeaveType $leaveType)
    {
        $data = $request->validated();

        $leaveType->update(
            collect($data)->only(['name', 'code', 'is_paid', 'requires_document', 'is_active'])->all()
        );

        $this->syncPolicy($leaveType, $data, $request->user()->id);

        return response()->json(['data' => $this->present($leaveType->fresh('policy'))]);
    }

    public function destroy(Request $request, LeaveType $leaveType)
    {
        abort_unless($request->user()->can(Permissions::COMPANY_MANAGE), 403);

        $leaveType->delete();

        return response()->json(null, 204);
    }

    private function syncPolicy(LeaveType $type, array $data, int $userId): void
    {
        if (! array_key_exists('days_per_year', $data)) {
            return;
        }

        LeavePolicy::query()->updateOrCreate(
            ['leave_type_id' => $type->id],
            [
                'days_per_year' => $data['days_per_year'],
                'carry_forward_max' => $data['carry_forward_max'] ?? 0,
                'created_by' => $userId,
            ],
        );
    }

    private function present(LeaveType $type): array
    {
        $policy = $type->relationLoaded('policy') ? $type->policy->first() : null;

        return [
            'id' => $type->id,
            'name' => $type->name,
            'code' => $type->code,
            'is_paid' => $type->is_paid,
            'requires_document' => $type->requires_document,
            'is_active' => $type->is_active,
            'days_per_year' => $policy?->days_per_year ?? 0,
            'carry_forward_max' => $policy?->carry_forward_max ?? 0,
        ];
    }
}
