<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'appraisal_assignment_id', 'final_score_basis_points', 'raw_score_basis_points',
    'calibrated_score_basis_points', 'grade', 'status', 'approved_by', 'approved_at',
    'acknowledged_at', 'rejected_reason', 'finalised_at',
])]
class AppraisalResult extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING_CALIBRATION = 'pending_calibration';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_APPEALED = 'appealed';

    public const STATUS_APPEAL_RESOLVED = 'appeal_resolved';

    protected function casts(): array
    {
        return ['finalised_at' => 'datetime', 'approved_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function assignment(): BelongsTo { return $this->belongsTo(AppraisalAssignment::class, 'appraisal_assignment_id'); }
    public function outcomes(): HasMany { return $this->hasMany(PerformanceOutcome::class); }
    public function calibrationAdjustments(): HasMany { return $this->hasMany(CalibrationAdjustment::class); }
    public function appeals(): HasMany { return $this->hasMany(AppraisalAppeal::class); }
}
