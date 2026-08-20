<?php

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_salary_id', 'salary_component_id', 'formula_revision_id', 'mode', 'amount', 'percent'])]
class EmployeeSalaryComponentOverride extends Model
{
    use BelongsToTenant;

    public const MODE_OVERRIDE = 'override';

    public const MODE_ADDITIONAL = 'additional';

    public const MODE_EXCLUDED = 'excluded';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'percent' => 'integer',
        ];
    }

    public function salary(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalary::class, 'employee_salary_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    public function formulaRevision(): BelongsTo
    {
        return $this->belongsTo(SalaryFormulaRevision::class, 'formula_revision_id');
    }
}
