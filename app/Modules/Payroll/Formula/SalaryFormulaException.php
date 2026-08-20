<?php

namespace App\Modules\Payroll\Formula;

use RuntimeException;

class SalaryFormulaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'INVALID_SALARY_FORMULA',
    ) {
        parent::__construct($message);
    }
}
