<?php

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Support\PayrollPreflight;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

class PayrollRunController extends Controller
{
    public function __construct(private readonly PayrollRunService $service)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_VIEW), 403);

        $runs = PayrollRun::query()
            ->latest('period')
            ->latest('id')
            ->get()
            ->map($this->presentSummary(...));

        return response()->json(['data' => $runs]);
    }

    public function preflight(Request $request, PayrollPreflight $preflight)
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_PROCESS), 403);

        $period = $this->validPeriod($request->query('period'));
        $checks = $preflight->check($period);

        return response()->json([
            'data' => [
                'period' => $period,
                'passed' => collect($checks)->every(fn ($c) => $c['passed']),
                'checks' => $checks,
            ],
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_PROCESS), 403);

        $period = $this->validPeriod($request->input('period'));
        $run = $this->service->createRun($period, $request->user());

        return response()->json(['data' => $this->presentSummary($run->refresh())], 201);
    }

    public function show(Request $request, PayrollRun $payrollRun)
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_VIEW), 403);

        $employees = $payrollRun->runEmployees()
            ->with('employee')
            ->get()
            ->map(fn (PayrollRunEmployee $re) => [
                'id' => $re->id,
                'employee' => ['id' => $re->employee->id, 'name' => $re->employee->full_name, 'number' => $re->employee->employee_number],
                'gross' => $re->gross,
                'total_statutory' => $re->total_statutory,
                'total_deductions' => $re->total_deductions,
                'net' => $re->net,
            ]);

        return response()->json([
            'data' => [
                ...$this->presentSummary($payrollRun),
                'employees' => $employees,
            ],
        ]);
    }

    public function employeeDetail(Request $request, PayrollRun $payrollRun, PayrollRunEmployee $runEmployee)
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_VIEW), 403);
        abort_unless($runEmployee->payroll_run_id === $payrollRun->id, 404);

        return response()->json([
            'data' => [
                'employee' => $runEmployee->snapshot['employee'] ?? null,
                'gross' => $runEmployee->gross,
                'net' => $runEmployee->net,
                'items' => $runEmployee->items()->get(['category', 'code', 'name', 'amount']),
            ],
        ]);
    }

    public function submit(Request $request, PayrollRun $payrollRun)
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_PROCESS), 403);

        $this->service->submit($payrollRun, $request->user());

        return response()->json(['data' => $this->presentSummary($payrollRun->refresh())]);
    }

    public function lock(Request $request, PayrollRun $payrollRun)
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_APPROVE), 403);

        $this->service->lock($payrollRun);

        return response()->json(['data' => $this->presentSummary($payrollRun->refresh())]);
    }

    private function presentSummary(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'period' => $run->period,
            'status' => $run->status,
            'employee_count' => $run->employee_count,
            'total_gross' => $run->total_gross,
            'total_statutory' => $run->total_statutory,
            'total_deductions' => $run->total_deductions,
            'total_net' => $run->total_net,
            'total_employer_cost' => $run->total_employer_cost,
            'submitted_at' => $run->submitted_at?->toISOString(),
            'approved_at' => $run->approved_at?->toISOString(),
            'locked_at' => $run->locked_at?->toISOString(),
        ];
    }

    private function validPeriod(?string $period): string
    {
        if (! $period || ! preg_match('/^\d{4}-\d{2}$/', $period)) {
            return Carbon::now()->format('Y-m');
        }

        return $period;
    }
}
