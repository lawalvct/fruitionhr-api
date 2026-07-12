<?php

namespace App\Modules\Employee\Controllers;

use App\Modules\Employee\Actions\AssignEmployee;
use App\Modules\Employee\Actions\CreateEmployee;
use App\Modules\Employee\Exports\EmployeesExport;
use App\Modules\Employee\Exports\EmployeeImportTemplateExport;
use App\Modules\Employee\Imports\EmployeesImport;
use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Requests\StoreAssignmentRequest;
use App\Modules\Employee\Requests\StoreEmployeeRequest;
use App\Modules\Employee\Requests\UpdateEmployeeRequest;
use App\Modules\Employee\Resources\EmployeeAssignmentResource;
use App\Modules\Employee\Resources\EmployeeResource;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function exportExcel(Request $request): mixed
    {
        Gate::authorize('viewAny', Employee::class);

        return Excel::download(new EmployeesExport($this->exportQuery($request)->get()), 'employees.xlsx');
    }

    public function exportPdf(Request $request): mixed
    {
        Gate::authorize('viewAny', Employee::class);
        $employees = $this->exportQuery($request)->get();

        return Pdf::loadView('employees.export-pdf', [
            'employees' => $employees,
            'tenantName' => app(CurrentTenant::class)->get()?->name ?? 'Company',
        ])->setPaper('a4', 'landscape')->download('employees.pdf');
    }

    public function importEmployees(Request $request): JsonResponse
    {
        Gate::authorize('create', Employee::class);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']]);

        $import = new EmployeesImport((int) $request->user()->id);
        Excel::import($import, $request->file('file'));

        return response()->json(['data' => [
            'imported' => $import->imported,
            'skipped' => $import->skipped,
            'errors' => $import->errors,
        ]]);
    }

    public function importTemplate(Request $request): mixed
    {
        Gate::authorize('viewAny', Employee::class);

        return Excel::download(new EmployeeImportTemplateExport, 'employees-import-template.xlsx');
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

    public function update(UpdateEmployeeRequest $request, Employee $employee, AssignEmployee $assignEmployee): EmployeeResource
    {
        Gate::authorize('update', $employee);

        $validated = $request->validated();
        $assignment = $validated['assignment'] ?? null;
        $contacts = $validated['contacts'] ?? null;
        $bankAccounts = $validated['bank_accounts'] ?? null;
        $statutory = $validated['statutory'] ?? null;
        unset($validated['assignment'], $validated['contacts'], $validated['bank_accounts'], $validated['statutory']);

        $employee->update($validated);

        if ($assignment !== null) {
            $assignEmployee->execute($employee, $assignment, $request->user()?->id);
        }
        if ($contacts !== null) {
            $employee->contacts()->delete();
            foreach ($contacts as $contact) $employee->contacts()->create([...$contact, 'created_by' => $request->user()?->id]);
        }
        if ($bankAccounts !== null) {
            $employee->bankAccounts()->delete();
            foreach ($bankAccounts as $account) $employee->bankAccounts()->create([...$account, 'created_by' => $request->user()?->id]);
        }
        if ($statutory !== null) {
            $employee->statutoryDetails()->updateOrCreate([], [...$statutory, 'created_by' => $request->user()?->id]);
        }

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

    public function uploadPhoto(Request $request, Employee $employee): EmployeeResource
    {
        Gate::authorize('update', $employee);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $disk = Storage::disk('local');

        if ($employee->photo_path && $disk->exists($employee->photo_path)) {
            $disk->delete($employee->photo_path);
        }

        $tenantId = app(CurrentTenant::class)->id();
        $path = $request->file('photo')->store("tenants/{$tenantId}/employees/{$employee->id}/avatar", 'local');
        $employee->update(['photo_path' => $path]);

        return new EmployeeResource($this->loadProfile($employee->refresh()));
    }

    public function showPhoto(Request $request, Employee $employee): StreamedResponse
    {
        Gate::authorize('view', $employee);
        abort_unless($employee->photo_path && Storage::disk('local')->exists($employee->photo_path), 404);

        return Storage::disk('local')->response($employee->photo_path);
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

    private function exportQuery(Request $request): Builder
    {
        $filters = $request->input('filter', []);
        $departmentId = is_array($filters) ? ($filters['department_id'] ?? null) : null;
        $status = is_array($filters) ? ($filters['employment_status'] ?? null) : null;

        return Employee::query()
            ->with(['currentAssignment.department', 'currentAssignment.position'])
            ->when($departmentId, fn (Builder $query) => $query->whereHas('currentAssignment', fn (Builder $assignment) => $assignment->where('department_id', $departmentId)))
            ->when($status, fn (Builder $query) => $query->where('employment_status', $status))
            ->orderBy('first_name');
    }
}
