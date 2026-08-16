<?php

namespace App\Modules\Reports\Controllers;

use App\Modules\Reports\Requests\ReportAnalysisRequest;
use App\Modules\Reports\Services\ReportCsvExportService;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportCsvExportController extends Controller
{
    public function __invoke(
        ReportAnalysisRequest $request,
        ReportCsvExportService $export,
        string $module,
    ): StreamedResponse {
        $validated = $request->validated();
        $year = (int) ($validated['year'] ?? now()->year);
        unset($validated['year']);

        return $export->download($module, $year, $validated);
    }
}
