<?php

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['salary_structure_id', 'salary_component_id', 'amount', 'percent'])]
class SalaryStructureComponent extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'amount' => 'integer', // kobo
            'percent' => 'integer',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
