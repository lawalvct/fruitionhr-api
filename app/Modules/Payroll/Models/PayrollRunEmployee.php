<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'payroll_run_id', 'employee_id', 'snapshot', 'gross', 'taxable_pay',
    'pensionable_pay', 'total_statutory', 'total_deductions', 'net',
    'pension_employer', 'nsitf',
])]
class PayrollRunEmployee extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'gross' => 'integer',
            'taxable_pay' => 'integer',
            'pensionable_pay' => 'integer',
            'total_statutory' => 'integer',
            'total_deductions' => 'integer',
            'net' => 'integer',
            'pension_employer' => 'integer',
            'nsitf' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }
}
