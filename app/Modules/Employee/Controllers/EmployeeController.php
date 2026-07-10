<?php

namespace App\Modules\Employee\Controllers;

use App\Modules\Employee\Actions\AssignEmployee;
use App\Modules\Employee\Actions\CreateEmployee;
use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Requests\StoreAssignmentRequest;
use App\Modules\Employee\Requests\StoreEmployeeRequest;
use App\Modules\Employee\Requests\UpdateEmployeeRequest;
use App\Modules\Employee\Resources\EmployeeAssignmentResource;
use App\Modules\Employee\Resources\EmployeeResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class EmployeeController extends Controller
{
    public function index(Request $request): mixed
    {
        Gate::authorize('viewAny', Employee::class);

        $query = QueryBuilder::for(Employee::query())
            ->with(['currentAssignment.department', 'currentAssignment.position'])
            ->allowedFilters(
                AllowedFilter::callback('search', function (Builder $query, mixed $value): void {
                    $search = trim((string) $value);

                    if ($search === '') {
                        return;
                    }

                    $query->where(function (Builder $inner) use ($search): void {
                        $inner->where('employee_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('middle_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('official_email', 'like', "%{$search}%")
                            ->orWhere('personal_email', 'like', "%{$search}%");
                    });
                }),
                AllowedFilter::callback('department_id', function (Builder $query, mixed $value): void {
                    $query->whereHas('currentAssignment', fn (Builder $assignment) => $assignment->where('department_id', $value));
                }),
                AllowedFilter::exact('employment_status'),
            )
            ->allowedSorts('employee_number', 'first_name', 'last_name', 'hired_at', 'created_at')
            ->defaultSort('first_name');

        return EmployeeResource::collection(
            $query->paginate(min((int) $request->integer('per_page', 15), 100))->appends($request->query())
        );
    }

    public function store(StoreEmployeeRequest $request, CreateEmployee $createEmployee): JsonResponse
    {
        Gate::authorize('create', Employee::class);

        $validated = $request->validated();
        $assignment = $validated['assignment'] ?? null;
        $contacts = $validated['contacts'] ?? [];
        $bankAccounts = $validated['bank_accounts'] ?? [];
        $statutory = $validated['statutory'] ?? null;

        unset($validated['assignment'], $validated['contacts'], $validated['bank_accounts'], $validated['statutory']);

        if ($assignment !== null && empty($assignment['effective_from'])) {
            $assignment['effective_from'] = $validated['hired_at'];
        }

        $employee = $createEmployee->execute($validated, $assignment, $request->user()?->id);

        foreach ($contacts as $contact) {
            $employee->contacts()->create([...$contact, 'created_by' => $request->user()?->id]);
        }

        foreach ($bankAccounts as $account) {
            $employee->bankAccounts()->create([...$account, 'created_by' => $request->user()?->id]);
        }

        if ($statutory !== null) {
            $employee->statutoryDetails()->create([...$statutory, 'created_by' => $request->user()?->id]);
        }

        return (new EmployeeResource($this->loadProfile($employee->refresh())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Employee $employee): EmployeeResource
    {
        Gate::authorize('view', $employee);

        return new EmployeeResource($this->loadProfile($employee));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        Gate::authorize('update', $employee);

        $employee->update($request->validated());

        return new EmployeeResource($this->loadProfile($employee->refresh()));
    }

    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('delete', $employee);

        $employee->delete();

        return response()->json(null, 204);
    }

    public function assign(StoreAssignmentRequest $request, Employee $employee, AssignEmployee $assignEmployee): EmployeeAssignmentResource
    {
        Gate::authorize('update', $employee);

        $assignment = $assignEmployee->execute($employee, $request->validated(), $request->user()?->id);

        return new EmployeeAssignmentResource($assignment->load([
            'branch',
            'department',
            'position',
            'jobGrade',
            'employmentType',
            'supervisor',
        ]));
    }

    private function loadProfile(Employee $employee): Employee
    {
        return $employee->load([
            'currentAssignment.branch',
            'currentAssignment.department',
            'currentAssignment.position',
            'currentAssignment.jobGrade',
            'currentAssignment.employmentType',
            'currentAssignment.supervisor',
            'employmentRecords.branch',
            'employmentRecords.department',
            'employmentRecords.position',
            'employmentRecords.jobGrade',
            'employmentRecords.employmentType',
            'employmentRecords.supervisor',
            'contacts',
            'bankAccounts',
            'statutoryDetails',
        ]);
    }
}
