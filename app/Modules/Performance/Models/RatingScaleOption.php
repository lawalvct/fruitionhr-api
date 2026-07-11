<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['rating_scale_id', 'label', 'min_score_basis_points', 'max_score_basis_points', 'sort_order'])]
class RatingScaleOption extends Model
{
    use BelongsToTenant;
    public function ratingScale(): BelongsTo { return $this->belongsTo(RatingScale::class); }
}
