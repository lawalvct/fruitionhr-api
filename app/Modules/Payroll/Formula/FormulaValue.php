<?php

namespace App\Modules\Payroll\Formula;

use Brick\Math\BigRational;

final readonly class FormulaValue
{
    public const MONEY = 'money';

    public const SCALAR = 'scalar';

    public function __construct(
        public BigRational $number,
        public string $kind,
    ) {}
}
