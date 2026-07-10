<?php

namespace App\Modules\Company\Models;

use Database\Factories\JobGradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'level', 'min_salary', 'max_salary', 'is_active', 'created_by'])]
class JobGrade extends CompanyModel
{
    protected static string $factory = JobGradeFactory::class;

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'min_salary' => 'integer',
            'max_salary' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }
}
