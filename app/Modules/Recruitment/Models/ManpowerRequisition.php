<?php

namespace App\Modules\Recruitment\Models;

use App\Models\User;
use App\Modules\Company\Models\Department;
use App\Modules\Company\Models\EmploymentType;
use App\Modules\Company\Models\Position;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\ManpowerRequisitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['department_id', 'position_id', 'employment_type_id', 'requested_by', 'title', 'headcount', 'target_start_date', 'reason', 'status', 'submitted_at', 'completed_at', 'created_by'])]
class ManpowerRequisition extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static string $factory = ManpowerRequisitionFactory::class;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return ['headcount' => 'integer', 'target_start_date' => 'date:Y-m-d', 'submitted_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function position(): BelongsTo { return $this->belongsTo(Position::class); }
    public function employmentType(): BelongsTo { return $this->belongsTo(EmploymentType::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function vacancies(): HasMany { return $this->hasMany(Vacancy::class); }

    public function workflowSummary(): string
    {
        return "{$this->title} ({$this->headcount} position".($this->headcount === 1 ? '' : 's').')';
    }
}
