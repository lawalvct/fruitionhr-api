<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\AppraisalTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['rating_scale_id', 'name', 'department', 'target_role', 'min_passing_basis_points', 'description', 'is_active', 'created_by'])]
class AppraisalTemplate extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;
    protected static string $factory = AppraisalTemplateFactory::class;
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function ratingScale(): BelongsTo { return $this->belongsTo(RatingScale::class); }
    public function items(): HasMany { return $this->hasMany(AppraisalTemplateItem::class); }
}
