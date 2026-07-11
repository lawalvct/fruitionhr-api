<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\AppraisalCycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'starts_at', 'ends_at', 'review_starts_at', 'review_ends_at', 'status', 'created_by'])]
class AppraisalCycle extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;
    protected static string $factory = AppraisalCycleFactory::class;
    protected function casts(): array { return ['starts_at' => 'date:Y-m-d', 'ends_at' => 'date:Y-m-d', 'review_starts_at' => 'date:Y-m-d', 'review_ends_at' => 'date:Y-m-d']; }
    public function assignments(): HasMany { return $this->hasMany(AppraisalAssignment::class); }
}
