<?php

namespace App\Modules\Payroll\Formula;

use App\Modules\Payroll\Models\SalaryFormulaRevision;

class SalaryFormulaDraftConflictException extends SalaryFormulaException
{
    public function __construct(public readonly ?SalaryFormulaRevision $currentDraft)
    {
        parent::__construct(
            'The formula draft changed after you opened it. Review the latest draft before saving or publishing.',
            'SALARY_FORMULA_DRAFT_CONFLICT',
        );
    }
}
