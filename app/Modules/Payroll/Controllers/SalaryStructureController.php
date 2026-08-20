<?php

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Formula\SalaryFormulaEngine;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Requests\SalaryStructureRequest;
use App\Modules\Payroll\Services\AdvancedSalaryFeature;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalaryStructureController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(
            $request->user()->can(Permissions::EMPLOYEES_VIEW_SALARY)
                || $request->user()->can(Permissions::PAYROLL_FORMULAS_MANAGE),
            403,
        );

        $structures = SalaryStructure::query()
            ->with(['components.component', 'components.formulaRevision'])
            ->orderBy('name')
            ->get()
            ->map($this->present(...));

        return response()->json(['data' => $structures]);
    }

    public function store(SalaryStructureRequest $request)
    {
        $structure = DB::transaction(function () use ($request) {
            $tenant = app(AdvancedSalaryFeature::class)->lockTenant();
            $structure = SalaryStructure::query()->create([
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
                'is_active' => $request->validated('is_active', true),
                'created_by' => $request->user()->id,
            ]);

            $this->syncComponents($structure, $request->validated('components', []), $tenant);

            return $structure;
        });

        return response()->json(['data' => $this->present($structure->load([
            'components.component',
            'components.formulaRevision',
        ]))], 201);
    }

    public function update(SalaryStructureRequest $request, SalaryStructure $salaryStructure)
    {
        DB::transaction(function () use ($request, $salaryStructure) {
            $tenant = app(AdvancedSalaryFeature::class)->lockTenant();
            $locked = SalaryStructure::query()
                ->whereKey($salaryStructure->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->update([
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
                'is_active' => $request->validated('is_active', true),
            ]);

            if ($request->has('components')) {
                $locked->components()->delete();
                $this->syncComponents($locked, $request->validated('components', []), $tenant);
            }
        });

        return response()->json(['data' => $this->present($salaryStructure->refresh()->load([
            'components.component',
            'components.formulaRevision',
        ]))]);
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

    private function syncComponents(
        SalaryStructure $structure,
        array $components,
        Tenant $tenant,
    ): void {
        $componentModels = SalaryComponent::query()
            ->with('publishedFormulaRevision')
            ->whereIn('id', collect($components)->pluck('salary_component_id'))
            ->get()
            ->keyBy('id');
        $requestedIds = collect($components)
            ->pluck('salary_component_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($componentModels->count() !== $requestedIds->count()
            || $componentModels->contains(fn (SalaryComponent $component): bool => ! $component->is_active)) {
            throw ValidationException::withMessages([
                'components' => 'Every salary structure component must still exist and be active when the structure is saved.',
            ]);
        }

        if ($componentModels->contains(fn (SalaryComponent $component): bool => $component->isReservedBasicSalaryComponent())) {
            throw ValidationException::withMessages([
                'components' => 'Basic Salary cannot be included in a salary structure; enter it per employee in Compensation.',
            ]);
        }

        if ($componentModels->contains(
            fn (SalaryComponent $component): bool => $component->calc_type === SalaryComponent::CALC_FORMULA,
        )) {
            app(AdvancedSalaryFeature::class)->assertTenantEnabled($tenant);
        }

        $componentIds = $requestedIds;
        foreach ($componentModels as $component) {
            if ($component->calc_type !== SalaryComponent::CALC_FORMULA) {
                continue;
            }

            $revision = $component->publishedFormulaRevision;
            if ($revision === null) {
                throw ValidationException::withMessages([
                    'components' => "Formula component {$component->name} has no published revision.",
                ]);
            }

            foreach (app(SalaryFormulaEngine::class)->dependencies($revision->definition) as $dependencyId) {
                if (! $componentIds->contains($dependencyId)) {
                    throw ValidationException::withMessages([
                        'components' => "Formula component {$component->name} requires salary component {$dependencyId} in the same structure.",
                    ]);
                }
            }
        }

        foreach ($components as $line) {
            /** @var SalaryComponent $component */
            $component = $componentModels->get($line['salary_component_id']);
            $structure->components()->create([
                'salary_component_id' => $line['salary_component_id'],
                'formula_revision_id' => $component->calc_type === SalaryComponent::CALC_FORMULA
                    ? $component->publishedFormulaRevision?->id
                    : null,
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
                'formula_revision_id' => $line->formula_revision_id,
                'uses_formula' => $line->formula_revision_id !== null,
                'formula' => $line->formulaRevision === null ? null : [
                    'revision_id' => $line->formulaRevision->id,
                    'version' => $line->formulaRevision->version,
                    'summary' => $line->formulaRevision->summary,
                    'checksum' => $line->formulaRevision->checksum,
                    'dependency_ids' => app(SalaryFormulaEngine::class)
                        ->dependencies($line->formulaRevision->definition),
                ],
            ])->all(),
        ];
    }
}
