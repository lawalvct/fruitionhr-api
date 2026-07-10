<?php

namespace App\Modules\Employee\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\EmployeeBankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['employee_id', 'bank_name', 'bank_code', 'account_number', 'account_name', 'is_primary', 'created_by'])]
class EmployeeBankAccount extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static string $factory = EmployeeBankAccountFactory::class;

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
