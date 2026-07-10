<?php

namespace App\Modules\Employee\Models;

use App\Modules\Company\Models\Branch;
use App\Modules\Company\Models\Department;
use App\Modules\Company\Models\EmploymentType;
use App\Modules\Company\Models\JobGrade;
use App\Modules\Company\Models\Position;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\EmployeeEmploymentRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_id',
    'branch_id',
    'department_id',
    'position_id',
    'job_grade_id',
    'employment_type_id',
    'supervisor_id',
    'effective_from',
    'effective_to',
    'is_current',
    'created_by',
])]
class EmployeeEmploymentRecord extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static string $factory = EmployeeEmploymentRecordFactory::class;

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_current' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class);
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }
}
