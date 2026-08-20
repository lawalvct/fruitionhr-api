<?php

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\SalaryComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    /**
     * Percentage of gross pay. For an earning the base is basic + every
     * gross-independent earning, since gross is defined as basic + all
     * earnings and a component cannot be a percentage of itself. For
     * deductions, employer costs and benefits in kind the base is the finished
     * gross — none of those are part of it. See SalaryResolver::amountsFor().
     */
    public const CALC_PERCENT_OF_GROSS = 'percent_of_gross';

    public const CALC_FORMULA = 'formula';

    /** Every calculation method a component may use. */
    public const CALC_TYPES = [self::CALC_FIXED, self::CALC_PERCENT, self::CALC_PERCENT_OF_GROSS, self::CALC_FORMULA];

    /** Calculation methods that carry a percentage rather than an amount. */
    public const PERCENT_CALC_TYPES = [self::CALC_PERCENT, self::CALC_PERCENT_OF_GROSS];

    public function isPercentBased(): bool
    {
        return in_array($this->calc_type, self::PERCENT_CALC_TYPES, true);
    }

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

    public function formulaRevisions(): HasMany
    {
        return $this->hasMany(SalaryFormulaRevision::class);
    }

    public function draftFormulaRevision(): HasOne
    {
        return $this->hasOne(SalaryFormulaRevision::class)
            ->where('status', SalaryFormulaRevision::STATUS_DRAFT)
            ->latestOfMany('version');
    }

    public function publishedFormulaRevision(): HasOne
    {
        return $this->hasOne(SalaryFormulaRevision::class)
            ->where('status', SalaryFormulaRevision::STATUS_PUBLISHED)
            ->latestOfMany('version');
    }
}
