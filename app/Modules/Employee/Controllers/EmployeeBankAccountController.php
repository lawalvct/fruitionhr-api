<?php

namespace App\Modules\Employee\Controllers;

use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Models\EmployeeBankAccount;
use App\Modules\Employee\Requests\EmployeeBankAccountRequest;
use App\Modules\Employee\Resources\EmployeeBankAccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class EmployeeBankAccountController extends Controller
{
    public function store(EmployeeBankAccountRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('update', $employee);
        $data = $request->validated();

        if (($data['is_primary'] ?? false) === true) {
            $employee->bankAccounts()->update(['is_primary' => false]);
        }

        $account = $employee->bankAccounts()->create([
            ...$data,
            'created_by' => $request->user()?->id,
        ]);

        return (new EmployeeBankAccountResource($account))->response()->setStatusCode(201);
    }

    public function update(EmployeeBankAccountRequest $request, Employee $employee, EmployeeBankAccount $bankAccount): EmployeeBankAccountResource
    {
        Gate::authorize('update', $employee);
        abort_unless((int) $bankAccount->employee_id === (int) $employee->id, 404);

        $data = $request->validated();

        if (($data['is_primary'] ?? false) === true) {
            $employee->bankAccounts()->whereKeyNot($bankAccount->id)->update(['is_primary' => false]);
        }

        $bankAccount->update($data);

        return new EmployeeBankAccountResource($bankAccount->refresh());
    }

    public function destroy(Employee $employee, EmployeeBankAccount $bankAccount): JsonResponse
    {
        Gate::authorize('update', $employee);
        abort_unless((int) $bankAccount->employee_id === (int) $employee->id, 404);

        $bankAccount->delete();

        return response()->json(null, 204);
    }
}
