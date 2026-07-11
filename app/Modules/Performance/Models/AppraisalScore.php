<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['appraisal_review_id', 'appraisal_template_item_id', 'score_basis_points', 'comments'])]
class AppraisalScore extends Model
{
    use BelongsToTenant;
    public function review(): BelongsTo { return $this->belongsTo(AppraisalReview::class, 'appraisal_review_id'); }
    public function templateItem(): BelongsTo { return $this->belongsTo(AppraisalTemplateItem::class, 'appraisal_template_item_id'); }
}
