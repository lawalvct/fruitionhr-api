<?php

namespace App\Modules\Company\Models;

use Database\Factories\EmploymentTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'is_active', 'created_by'])]
class EmploymentType extends CompanyModel
{
    protected static string $factory = EmploymentTypeFactory::class;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
