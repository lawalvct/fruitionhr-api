<?php

namespace App\Modules\Employee\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\EmployeeContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['employee_id', 'type', 'name', 'relationship', 'phone', 'email', 'address', 'created_by'])]
class EmployeeContact extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static string $factory = EmployeeContactFactory::class;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
