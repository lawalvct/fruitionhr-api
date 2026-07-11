<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['appraisal_assignment_id', 'final_score_basis_points', 'grade', 'status', 'finalised_at'])]
class AppraisalResult extends Model
{
    use BelongsToTenant;
    protected function casts(): array { return ['finalised_at' => 'datetime']; }
    public function assignment(): BelongsTo { return $this->belongsTo(AppraisalAssignment::class, 'appraisal_assignment_id'); }
    public function outcomes(): HasMany { return $this->hasMany(PerformanceOutcome::class); }
}
