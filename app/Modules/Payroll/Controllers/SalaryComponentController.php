<?php

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Formula\SalaryFormulaEngine;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\EmployeeSalaryComponentOverride;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryFormulaRevision;
use App\Modules\Payroll\Models\SalaryStructureComponent;
use App\Modules\Payroll\Requests\SalaryComponentRequest;
use App\Modules\Payroll\Services\AdvancedSalaryFeature;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalaryComponentController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(
            $request->user()->can(Permissions::EMPLOYEES_VIEW_SALARY)
                || $request->user()->can(Permissions::PAYROLL_FORMULAS_MANAGE),
            403,
        );

        return response()->json([
            'data' => SalaryComponent::query()
                ->with(['draftFormulaRevision', 'publishedFormulaRevision'])
                ->orderBy('name')
                ->get()
                ->map($this->present(...)),
        ]);
    }

    public function store(SalaryComponentRequest $request)
    {
        $component = DB::transaction(function () use ($request): SalaryComponent {
            $tenant = app(AdvancedSalaryFeature::class)->lockTenant();
            if ($request->validated('calc_type') === SalaryComponent::CALC_FORMULA) {
                app(AdvancedSalaryFeature::class)->assertTenantEnabled($tenant);
            }

            return SalaryComponent::query()->create([
                ...$request->validated(),
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json(['data' => $this->present($component->load(['draftFormulaRevision', 'publishedFormulaRevision']))], 201);
    }

    public function update(SalaryComponentRequest $request, SalaryComponent $salaryComponent)
    {
        $salaryComponent = DB::transaction(function () use ($request, $salaryComponent): SalaryComponent {
            $tenant = app(AdvancedSalaryFeature::class)->lockTenant();
            if ($request->validated('calc_type') === SalaryComponent::CALC_FORMULA) {
                app(AdvancedSalaryFeature::class)->assertTenantEnabled($tenant);
            }

            $locked = SalaryComponent::query()->whereKey($salaryComponent->id)->lockForUpdate()->firstOrFail();
            $protected = $this->isProtectedByPublishedFormula($locked);

            if ($this->isUsedByLegacySalary($locked)) {
                foreach (['name', 'code', 'type', 'calc_type', 'percent', 'is_taxable', 'is_pensionable'] as $field) {
                    if (array_key_exists($field, $request->validated())
                        && $request->validated($field) !== $locked->{$field}) {
                        throw ValidationException::withMessages([
                            $field => 'This component affects legacy salary records without immutable snapshots. Create a new component and structure before changing payroll or label fields.',
                        ]);
                    }
                }
            }

            foreach (['code', 'type', 'calc_type'] as $field) {
                if (array_key_exists($field, $request->validated())
                    && $request->validated($field) !== $locked->{$field}
                    && $protected) {
                    throw ValidationException::withMessages([
                        $field => 'This field cannot be changed after the component is published or referenced by a published formula.',
                    ]);
                }
            }

            $locked->update($request->validated());

            return $locked;
        });

        return response()->json(['data' => $this->present(
            $salaryComponent->refresh()->load(['draftFormulaRevision', 'publishedFormulaRevision'])
        )]);
    }

    public function destroy(Request $request, SalaryComponent $salaryComponent)
    {
        abort_unless($request->user()->can(Permissions::EMPLOYEES_MANAGE_SALARY), 403);
        if ($salaryComponent->calc_type === SalaryComponent::CALC_FORMULA) {
            abort_unless($request->user()->can(Permissions::PAYROLL_FORMULAS_MANAGE), 403);
        }

        DB::transaction(function () use ($salaryComponent): void {
            app(AdvancedSalaryFeature::class)->lockTenant();
            $locked = SalaryComponent::query()->whereKey($salaryComponent->id)->lockForUpdate()->firstOrFail();

            if (SalaryStructureComponent::query()->where('salary_component_id', $locked->id)->exists()
                || EmployeeSalaryComponentOverride::query()->where('salary_component_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'component' => 'This component is used by a salary structure. Set it inactive instead of deleting it.',
                ]);
            }

            if ($this->isProtectedByPublishedFormula($locked)
                || $locked->formulaRevisions()->exists()) {
                throw ValidationException::withMessages([
                    'component' => 'A component with formula history, or referenced by a published formula, cannot be deleted. Set it inactive instead.',
                ]);
            }

            $locked->delete();
        });

        return response()->json(null, 204);
    }

    private function present(SalaryComponent $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'code' => $c->code,
            'type' => $c->type,
            'calc_type' => $c->calc_type,
            'percent' => $c->percent,
            'is_taxable' => $c->is_taxable,
            'is_pensionable' => $c->is_pensionable,
            'is_active' => $c->is_active,
            'formula' => [
                'has_draft' => $c->draftFormulaRevision !== null,
                'published_revision_id' => $c->publishedFormulaRevision?->id,
                'published_version' => $c->publishedFormulaRevision?->version,
                'summary' => $c->publishedFormulaRevision?->summary,
                'dependency_ids' => $c->publishedFormulaRevision === null
                    ? []
                    : app(SalaryFormulaEngine::class)->dependencies($c->publishedFormulaRevision->definition),
            ],
        ];
    }

    private function isProtectedByPublishedFormula(SalaryComponent $component): bool
    {
        if ($component->formulaRevisions()
            ->where('status', SalaryFormulaRevision::STATUS_PUBLISHED)
            ->exists()) {
            return true;
        }

        $engine = app(SalaryFormulaEngine::class);

        return SalaryFormulaRevision::query()
            ->where('status', SalaryFormulaRevision::STATUS_PUBLISHED)
            ->get(['definition'])
            ->contains(fn (SalaryFormulaRevision $revision): bool => in_array(
                $component->id,
                $engine->dependencies($revision->definition),
                true,
            ));
    }

    private function isUsedByLegacySalary(SalaryComponent $component): bool
    {
        $structureIds = SalaryStructureComponent::query()
            ->where('salary_component_id', $component->id)
            ->select('salary_structure_id');

        return EmployeeSalary::query()
            ->whereNull('definition_snapshot')
            ->where(function ($query) use ($component, $structureIds): void {
                $query->whereIn('salary_structure_id', $structureIds)
                    ->orWhereHas('componentOverrides', fn ($overrides) => $overrides
                        ->where('salary_component_id', $component->id));
            })
            ->exists();
    }
}
