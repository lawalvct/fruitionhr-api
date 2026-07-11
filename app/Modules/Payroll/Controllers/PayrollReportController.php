<?php

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Exports\PayrollJournalExport;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollJournalService;
use App\Modules\Payroll\Services\PayrollVarianceService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;

class PayrollReportController extends Controller
{
    public function variance(Request $request, PayrollRun $payrollRun, PayrollVarianceService $service)
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_VIEW), 403);

        return response()->json(['data' => $service->forRun($payrollRun)]);
    }

    public function journal(Request $request, PayrollRun $payrollRun, PayrollJournalService $service)
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_VIEW), 403);

        return response()->json(['data' => $service->forRun($payrollRun)]);
    }

    public function journalExport(Request $request, PayrollRun $payrollRun, PayrollJournalService $service)
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_VIEW), 403);

        $journal = $service->forRun($payrollRun);

        return Excel::download(
            new PayrollJournalExport($journal),
            "payroll-journal-{$payrollRun->period}.xlsx",
        );
    }
}
