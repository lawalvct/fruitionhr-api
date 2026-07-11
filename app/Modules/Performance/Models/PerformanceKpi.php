<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\PerformanceKpiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['performance_category_id', 'name', 'description', 'measurement_unit', 'target_description', 'is_active', 'created_by'])]
class PerformanceKpi extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;
    protected static string $factory = PerformanceKpiFactory::class;
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function category(): BelongsTo { return $this->belongsTo(PerformanceCategory::class, 'performance_category_id'); }
}
