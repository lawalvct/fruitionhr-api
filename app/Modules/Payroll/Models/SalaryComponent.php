<?php

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\SalaryComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'code', 'type', 'calc_type', 'percent',
    'is_taxable', 'is_pensionable', 'is_active', 'created_by',
])]
class SalaryComponent extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static string $factory = SalaryComponentFactory::class;

    public const TYPE_EARNING = 'earning';

    public const TYPE_DEDUCTION = 'deduction';

    public const TYPE_EMPLOYER_CONTRIBUTOR = 'employer_contributor';

    public const TYPE_FRINGE_BENEFIT = 'fringe_benefit';

    public const RESERVED_BASIC_CODE = 'BASIC';

    public const CALC_FIXED = 'fixed';

    public const CALC_PERCENT = 'percent_of_basic';

    protected function casts(): array
    {
        return [
            'percent' => 'integer',
            'is_taxable' => 'boolean',
            'is_pensionable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function isReservedBasicSalary(?string $name, ?string $code): bool
    {
        return strtoupper(trim((string) $code)) === self::RESERVED_BASIC_CODE
            || strcasecmp(trim((string) $name), 'Basic Salary') === 0;
    }

    public function isReservedBasicSalaryComponent(): bool
    {
        return self::isReservedBasicSalary($this->name, $this->code);
    }
}
