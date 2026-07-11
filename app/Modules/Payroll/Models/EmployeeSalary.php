<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'salary_structure_id', 'basic_salary',
    'effective_from', 'effective_to', 'is_current', 'created_by',
])]
class EmployeeSalary extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'basic_salary' => 'integer', // kobo
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_current' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }
}
