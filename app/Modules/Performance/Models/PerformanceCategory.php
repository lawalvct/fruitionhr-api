<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\PerformanceCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'description', 'is_active', 'created_by'])]
class PerformanceCategory extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;
    protected static string $factory = PerformanceCategoryFactory::class;
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function kpis(): HasMany { return $this->hasMany(PerformanceKpi::class); }
}
