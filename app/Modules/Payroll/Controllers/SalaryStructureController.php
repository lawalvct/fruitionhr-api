<?php

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Requests\SalaryStructureRequest;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalaryStructureController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can(Permissions::EMPLOYEES_VIEW_SALARY), 403);

        $structures = SalaryStructure::query()
            ->with('components.component')
            ->orderBy('name')
            ->get()
            ->map($this->present(...));

        return response()->json(['data' => $structures]);
    }

    public function store(SalaryStructureRequest $request)
    {
        $structure = DB::transaction(function () use ($request) {
            $structure = SalaryStructure::query()->create([
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
                'is_active' => $request->validated('is_active', true),
                'created_by' => $request->user()->id,
            ]);

            $this->syncComponents($structure, $request->validated('components', []));

            return $structure;
        });

        return response()->json(['data' => $this->present($structure->load('components.component'))], 201);
    }

    public function update(SalaryStructureRequest $request, SalaryStructure $salaryStructure)
    {
        DB::transaction(function () use ($request, $salaryStructure) {
            $salaryStructure->update([
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
                'is_active' => $request->validated('is_active', true),
            ]);

            if ($request->has('components')) {
                $salaryStructure->components()->delete();
                $this->syncComponents($salaryStructure, $request->validated('components', []));
            }
        });

        return response()->json(['data' => $this->present($salaryStructure->load('components.component'))]);
    }

    public function destroy(Request $request, SalaryStructure $salaryStructure)
    {
        abort_unless($request->user()->can(Permissions::EMPLOYEES_MANAGE_SALARY), 403);

        if (EmployeeSalary::query()->where('salary_structure_id', $salaryStructure->id)->exists()) {
            throw ValidationException::withMessages([
                'structure' => 'This structure is assigned to an employee. Set it inactive instead of deleting it.',
            ]);
        }

        $salaryStructure->delete();

        return response()->json(null, 204);
    }

    private function syncComponents(SalaryStructure $structure, array $components): void
    {
        foreach ($components as $line) {
            $structure->components()->create([
                'salary_component_id' => $line['salary_component_id'],
                'amount' => $line['amount'] ?? null,
                'percent' => $line['percent'] ?? null,
            ]);
        }
    }

    private function present(SalaryStructure $structure): array
    {
        return [
            'id' => $structure->id,
            'name' => $structure->name,
            'description' => $structure->description,
            'is_active' => $structure->is_active,
            'components' => $structure->components->map(fn ($line) => [
                'id' => $line->id,
                'salary_component_id' => $line->salary_component_id,
                'component_name' => $line->component?->name,
                'component_code' => $line->component?->code,
                'type' => $line->component?->type,
                'amount' => $line->amount,
                'percent' => $line->percent,
            ])->all(),
        ];
    }
}
