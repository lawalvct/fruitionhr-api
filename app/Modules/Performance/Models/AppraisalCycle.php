<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\AppraisalCycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'appraisal_type', 'starts_at', 'ends_at', 'review_starts_at', 'review_ends_at', 'status', 'self_review_enabled', 'calibration_enabled', 'appeal_window_days', 'created_by'])]
class AppraisalCycle extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** Appraisal types from the client build spec §2 — behaviour is configuration, not code paths. */
    public const TYPES = [
        'annual', 'mid_year', 'quarterly', 'monthly_checkin', 'probation', 'promotion',
        'salary_review', 'training_needs', 'competency', 'leadership', 'feedback_360',
        'project', 'goal_okr', 'behavioural', 'exit', 'contract_renewal', 'succession', 'sales',
    ];

    protected static string $factory = AppraisalCycleFactory::class;
    protected function casts(): array { return ['starts_at' => 'date:Y-m-d', 'ends_at' => 'date:Y-m-d', 'review_starts_at' => 'date:Y-m-d', 'review_ends_at' => 'date:Y-m-d', 'self_review_enabled' => 'boolean', 'calibration_enabled' => 'boolean']; }
    public function assignments(): HasMany { return $this->hasMany(AppraisalAssignment::class); }
}
