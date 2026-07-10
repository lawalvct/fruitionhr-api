<?php

namespace App\Modules\Employee\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\EmployeeStatutoryDetailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['employee_id', 'tax_id', 'pension_pin', 'pension_fund_administrator', 'nhf_number', 'created_by'])]
class EmployeeStatutoryDetail extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static string $factory = EmployeeStatutoryDetailFactory::class;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
