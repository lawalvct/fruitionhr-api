<?php

namespace App\Modules\Payroll\Jobs;

use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Support\Tenancy\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Calculates a payroll run off the request cycle. Carries tenant_id explicitly
 * (TenantAware) and re-establishes tenant context before touching any model.
 */
class CalculatePayrollRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public int $payrollRunId;

    public function __construct(PayrollRun $run)
    {
        $this->payrollRunId = $run->id;
        $this->captureTenantContext();
        $this->onQueue('payroll');
    }

    public function handle(PayrollRunService $service): void
    {
        $this->restoreTenantContext();

        $run = PayrollRun::query()->find($this->payrollRunId);

        if ($run !== null) {
            $service->process($run);
        }
    }
}
