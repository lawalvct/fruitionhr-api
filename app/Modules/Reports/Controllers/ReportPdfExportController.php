<?php

namespace App\Modules\Reports\Controllers;

use App\Modules\Reports\Requests\ReportAnalysisRequest;
use App\Modules\Reports\Services\ReportPdfExportService;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

final class ReportPdfExportController extends Controller
{
    public function __invoke(
        ReportAnalysisRequest $request,
        ReportPdfExportService $export,
        string $module,
    ): Response {
        $validated = $request->validated();
        $year = (int) ($validated['year'] ?? now()->year);
        unset($validated['year']);

        return $export->download($module, $year, $validated);
    }
}
