<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'employee_id', 'salary_structure_id', 'basic_salary',
    'effective_from', 'effective_to', 'is_current', 'created_by',
    'change_type', 'change_reason',
])]
class EmployeeSalary extends Model
{
    use BelongsToTenant;

    public const CHANGE_ASSIGNMENT = 'assignment';

    public const CHANGE_COMPENSATION = 'compensation_update';

    public const CHANGE_BASIC_INCREASE = 'basic_salary_increase';

    protected function casts(): array
    {
        return [
            'basic_salary' => 'integer', // kobo
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_current' => 'boolean',
        ];
    }

    public function scopeEffectiveOn(Builder $query, string|\DateTimeInterface $date): Builder
    {
        $date = Carbon::parse($date)->toDateString();

        return $query
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function componentOverrides(): HasMany
    {
        return $this->hasMany(EmployeeSalaryComponentOverride::class);
    }
}
