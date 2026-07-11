<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['appraisal_reviewer_id', 'comments', 'submitted_at'])]
class AppraisalReview extends Model
{
    use BelongsToTenant;
    protected function casts(): array { return ['submitted_at' => 'datetime']; }
    public function reviewer(): BelongsTo { return $this->belongsTo(AppraisalReviewer::class, 'appraisal_reviewer_id'); }
    public function scores(): HasMany { return $this->hasMany(AppraisalScore::class); }
}
