<?php

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Requests\SalaryComponentRequest;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SalaryComponentController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can(Permissions::EMPLOYEES_VIEW_SALARY), 403);

        return response()->json([
            'data' => SalaryComponent::query()->orderBy('name')->get()->map($this->present(...)),
        ]);
    }

    public function store(SalaryComponentRequest $request)
    {
        $component = SalaryComponent::query()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->present($component)], 201);
    }

    public function update(SalaryComponentRequest $request, SalaryComponent $salaryComponent)
    {
        $salaryComponent->update($request->validated());

        return response()->json(['data' => $this->present($salaryComponent->refresh())]);
    }

    public function destroy(Request $request, SalaryComponent $salaryComponent)
    {
        abort_unless($request->user()->can(Permissions::EMPLOYEES_MANAGE_SALARY), 403);

        $salaryComponent->delete();

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
        ];
    }
}
