<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['appraisal_template_id', 'performance_kpi_id', 'weight'])]
class AppraisalTemplateItem extends Model
{
    use BelongsToTenant;
    public function template(): BelongsTo { return $this->belongsTo(AppraisalTemplate::class, 'appraisal_template_id'); }
    public function kpi(): BelongsTo { return $this->belongsTo(PerformanceKpi::class, 'performance_kpi_id'); }
}
