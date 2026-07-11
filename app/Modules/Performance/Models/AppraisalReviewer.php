<?php

namespace App\Modules\Performance\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['appraisal_assignment_id', 'reviewer_user_id', 'reviewer_type', 'weight', 'status', 'submitted_at'])]
class AppraisalReviewer extends Model
{
    use BelongsToTenant;
    protected function casts(): array { return ['submitted_at' => 'datetime']; }
    public function assignment(): BelongsTo { return $this->belongsTo(AppraisalAssignment::class, 'appraisal_assignment_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'reviewer_user_id'); }
    public function review(): HasOne { return $this->hasOne(AppraisalReview::class); }
}
