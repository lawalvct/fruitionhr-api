<?php

namespace App\Modules\Attendance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'start_time', 'end_time', 'grace_minutes', 'working_days', 'is_active', 'created_by'])]
class Shift extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static string $factory = ShiftFactory::class;

    protected function casts(): array
    {
        return [
            'working_days' => 'array',
            'grace_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }
}
