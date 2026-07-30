<?php

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Exports\BankScheduleExport;
use App\Modules\Payroll\Exports\StatutoryReportExport;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Support\Authorization\Permissions;
use App\Support\Money\Naira;
use App\Support\Tenancy\CurrentTenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class PayrollOutputController extends Controller
{
    /** Outputs are only available once the run is at least approved. */
    private const DOWNLOADABLE = [
        PayrollRun::STATUS_APPROVED,
        PayrollRun::STATUS_LOCKED,
        PayrollRun::STATUS_PAID,
    ];

    public function payslip(Request $request, PayrollRun $payrollRun, PayrollRunEmployee $runEmployee)
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_VIEW), 403);
        abort_unless($runEmployee->payroll_run_id === $payrollRun->id, 404);
        $this->assertDownloadable($payrollRun);

        $items = $runEmployee->items;
        $earnings = $items->where('category', PayrollItem::CATEGORY_EARNING);
        $deductions = $items->whereIn('category', [PayrollItem::CATEGORY_STATUTORY, PayrollItem::CATEGORY_DEDUCTION]);
        $employerContributions = $items->where('category', PayrollItem::CATEGORY_EMPLOYER);
        $fringeBenefits = $items->where('category', PayrollItem::CATEGORY_FRINGE_BENEFIT);
        $payDate = $payrollRun->locked_at ?? $payrollRun->approved_at ?? $payrollRun->submitted_at;

        $pdf = Pdf::loadView('payroll.payslip', [
            'company' => app(CurrentTenant::class)->get()?->name ?? 'Company',
            'logoDataUri' => $this->tenantLogoDataUri(),
            'periodLabel' => Carbon::createFromFormat('Y-m', $payrollRun->period)->format('F Y'),
            'employee' => $runEmployee->snapshot['employee'] ?? ['name' => '', 'employee_number' => ''],
            'structure' => $runEmployee->snapshot['structure'] ?? null,
            'status' => $payrollRun->status,
            'reference' => "PR-{$payrollRun->id}-{$runEmployee->id}",
            'payDateFormatted' => $payDate?->format('d M Y'),
            'generatedAt' => now()->format('d M Y, H:i'),
            'earnings' => $earnings->map($this->line(...))->values(),
            'deductions' => $deductions->map($this->line(...))->values(),
            'employerContributions' => $employerContributions->map($this->line(...))->values(),
            'fringeBenefits' => $fringeBenefits->map($this->line(...))->values(),
            'grossFormatted' => Naira::format($runEmployee->gross),
            'deductionsFormatted' => Naira::format($runEmployee->total_deductions),
            'netFormatted' => Naira::format($runEmployee->net),
        ]);

        $name = str($runEmployee->snapshot['employee']['employee_number'] ?? 'payslip')->slug();

        return $pdf->download("payslip-{$name}-{$payrollRun->period}.pdf");
    }

    public function bankSchedule(Request $request, PayrollRun $payrollRun)
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_VIEW), 403);
        $this->assertDownloadable($payrollRun);

        return Excel::download(
            new BankScheduleExport($payrollRun),
            "bank-schedule-{$payrollRun->period}.xlsx",
        );
    }

    public function statutoryReport(Request $request, PayrollRun $payrollRun)
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_VIEW), 403);
        $this->assertDownloadable($payrollRun);

        $type = $request->query('type', 'paye');
        abort_unless(in_array($type, ['paye', 'pension', 'nhf', 'nsitf'], true), 422);

        return Excel::download(
            new StatutoryReportExport($payrollRun, $type),
            "{$type}-report-{$payrollRun->period}.xlsx",
        );
    }

    private function assertDownloadable(PayrollRun $run): void
    {
        abort_unless(
            in_array($run->status, self::DOWNLOADABLE, true),
            409,
            'Payroll outputs are available after the run is approved.',
        );
    }

    private function line(PayrollItem $item): array
    {
        return ['name' => $item->name, 'formatted' => Naira::format($item->amount)];
    }

    private function tenantLogoDataUri(): ?string
    {
        $tenant = app(CurrentTenant::class)->get();
        $disk = Storage::disk('local');

        if (! $tenant?->logo_path || ! $disk->exists($tenant->logo_path)) {
            return null;
        }

        $mime = $disk->mimeType($tenant->logo_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($tenant->logo_path));
    }
}
