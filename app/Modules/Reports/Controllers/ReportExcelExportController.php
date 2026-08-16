<?php

namespace App\Modules\Reports\Controllers;

use App\Modules\Reports\Exports\ReportWorkbookExport;
use App\Modules\Reports\Requests\ReportAnalysisRequest;
use App\Modules\Reports\Services\ReportAnalysisService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ReportExcelExportController extends Controller
{
    public function __invoke(
        ReportAnalysisRequest $request,
        ReportAnalysisService $analysis,
        CurrentTenant $currentTenant,
        string $module,
    ): BinaryFileResponse {
        $validated = $request->validated();
        $year = (int) ($validated['year'] ?? now()->year);
        unset($validated['year']);

        $report = $analysis->build($module, $year, $validated, null);
        $tenantName = $currentTenant->get()?->name ?? 'Company';
        $filename = sprintf('%s-report-%d.xlsx', Str::slug($module), $year);

        return Excel::download(
            new ReportWorkbookExport($report, $tenantName),
            $filename,
            ExcelFormat::XLSX,
            [
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
