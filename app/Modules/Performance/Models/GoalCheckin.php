<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['goal_id', 'progress', 'current_value', 'comment', 'created_by'])]
class GoalCheckin extends Model
{
    use BelongsToTenant;
    public function goal(): BelongsTo { return $this->belongsTo(Goal::class); }
}
