<?php

namespace App\Modules\Performance\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit trail row for every calibration/appeal score change (spec §4.5). */
#[Fillable(['appraisal_result_id', 'adjusted_by', 'old_score_basis_points', 'new_score_basis_points', 'justification'])]
class CalibrationAdjustment extends Model
{
    use BelongsToTenant;

    public function result(): BelongsTo { return $this->belongsTo(AppraisalResult::class, 'appraisal_result_id'); }
    public function adjustedBy(): BelongsTo { return $this->belongsTo(User::class, 'adjusted_by'); }
}
