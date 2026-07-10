<?php

namespace App\Modules\Leave\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'code', 'is_paid', 'requires_document', 'is_active', 'created_by'])]
class LeaveType extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static string $factory = LeaveTypeFactory::class;

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'requires_document' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function policy(): HasMany
    {
        return $this->hasMany(LeavePolicy::class);
    }
}
