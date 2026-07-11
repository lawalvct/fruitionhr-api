<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['appraisal_result_id', 'type', 'notes', 'created_by'])]
class PerformanceOutcome extends Model
{
    use BelongsToTenant;
    public function result(): BelongsTo { return $this->belongsTo(AppraisalResult::class, 'appraisal_result_id'); }
}
