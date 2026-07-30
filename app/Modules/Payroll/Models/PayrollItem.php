<?php

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payroll_run_employee_id', 'category', 'code', 'name', 'amount'])]
class PayrollItem extends Model
{
    use BelongsToTenant;

    public const CATEGORY_EARNING = 'earning';

    public const CATEGORY_STATUTORY = 'statutory';

    public const CATEGORY_DEDUCTION = 'deduction';

    public const CATEGORY_EMPLOYER = 'employer';

    public const CATEGORY_FRINGE_BENEFIT = 'fringe_benefit';

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function runEmployee(): BelongsTo
    {
        return $this->belongsTo(PayrollRunEmployee::class, 'payroll_run_employee_id');
    }
}
