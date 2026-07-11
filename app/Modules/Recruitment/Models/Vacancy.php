<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Company\Models\EmploymentType;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\VacancyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['manpower_requisition_id', 'employment_type_id', 'title', 'code', 'description', 'requirements', 'location', 'positions_available', 'opens_at', 'closes_at', 'status', 'created_by'])]
class Vacancy extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static string $factory = VacancyFactory::class;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected function casts(): array
    {
        return ['positions_available' => 'integer', 'opens_at' => 'date:Y-m-d', 'closes_at' => 'date:Y-m-d'];
    }

    public function requisition(): BelongsTo { return $this->belongsTo(ManpowerRequisition::class, 'manpower_requisition_id'); }
    public function employmentType(): BelongsTo { return $this->belongsTo(EmploymentType::class); }
    public function applications(): HasMany { return $this->hasMany(Application::class); }
}
