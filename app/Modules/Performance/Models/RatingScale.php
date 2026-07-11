<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'description', 'is_active', 'created_by'])]
class RatingScale extends Model
{
    use BelongsToTenant, SoftDeletes;
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function options(): HasMany { return $this->hasMany(RatingScaleOption::class)->orderBy('sort_order'); }
}
