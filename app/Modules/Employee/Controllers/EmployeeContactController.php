<?php

namespace App\Modules\Employee\Controllers;

use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Models\EmployeeContact;
use App\Modules\Employee\Requests\EmployeeContactRequest;
use App\Modules\Employee\Resources\EmployeeContactResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class EmployeeContactController extends Controller
{
    public function store(EmployeeContactRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('update', $employee);

        $contact = $employee->contacts()->create([
            ...$request->validated(),
            'created_by' => $request->user()?->id,
        ]);

        return (new EmployeeContactResource($contact))->response()->setStatusCode(201);
    }

    public function update(EmployeeContactRequest $request, Employee $employee, EmployeeContact $contact): EmployeeContactResource
    {
        Gate::authorize('update', $employee);
        abort_unless((int) $contact->employee_id === (int) $employee->id, 404);

        $contact->update($request->validated());

        return new EmployeeContactResource($contact->refresh());
    }

    public function destroy(Employee $employee, EmployeeContact $contact): JsonResponse
    {
        Gate::authorize('update', $employee);
        abort_unless((int) $contact->employee_id === (int) $employee->id, 404);

        $contact->delete();

        return response()->json(null, 204);
    }
}
