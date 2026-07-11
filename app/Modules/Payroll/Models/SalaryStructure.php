<?php

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\SalaryStructureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'description', 'is_active', 'created_by'])]
class SalaryStructure extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static string $factory = SalaryStructureFactory::class;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function components(): HasMany
    {
        return $this->hasMany(SalaryStructureComponent::class);
    }
}
