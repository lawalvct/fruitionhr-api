<?php

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'config', 'effective_from', 'effective_to', 'is_active'])]
class StatutoryRule extends Model
{
    use BelongsToTenant;

    public const TYPE_PAYE = 'paye';

    public const TYPE_PENSION = 'pension';

    public const TYPE_NHF = 'nhf';

    public const TYPE_NSITF = 'nsitf';

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }
}
