<?php

namespace App\Modules\SelfService\Services;

use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Modules\SelfService\Models\ProfileUpdateRequest;
use App\Modules\SelfService\Requests\StoreProfileUpdateRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfileUpdateService
{
    public function __construct(private readonly WorkflowService $workflow)
    {
    }

    public function submit(Employee $employee, User $requestedBy, array $input): ProfileUpdateRequest
    {
        $requested = [];
        $current = [];

        foreach (StoreProfileUpdateRequest::FIELDS as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            if ($employee->getAttribute($field) === $input[$field]) {
                continue;
            }

            $current[$field] = $employee->getAttribute($field);
            $requested[$field] = $input[$field];
        }

        if ($requested === []) {
            throw ValidationException::withMessages([
                'profile' => 'At least one submitted value must be different from your current profile.',
            ]);
        }

        return DB::transaction(function () use ($employee, $requestedBy, $current, $requested): ProfileUpdateRequest {
            $updateRequest = ProfileUpdateRequest::query()->create([
                'employee_id' => $employee->id,
                'requested_by' => $requestedBy->id,
                'current_values' => $current,
                'requested_values' => $requested,
                'status' => ProfileUpdateRequest::STATUS_PENDING,
                'submitted_at' => now(),
            ]);

            $this->workflow->submit($updateRequest, 'profile_update', $requestedBy);

            return $updateRequest;
        });
    }

    public function markApproved(ProfileUpdateRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            $request->employee->fill($request->requested_values)->save();
            $request->update([
                'status' => ProfileUpdateRequest::STATUS_APPROVED,
                'completed_at' => now(),
            ]);
        });
    }

    public function markRejected(ProfileUpdateRequest $request): void
    {
        $request->update([
            'status' => ProfileUpdateRequest::STATUS_REJECTED,
            'completed_at' => now(),
        ]);
    }
}
