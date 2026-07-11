<?php

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['period', 'year', 'month', 'status'])]
class PayPeriod extends Model
{
    use BelongsToTenant;

    public function runs(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }
}
